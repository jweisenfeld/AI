-- ============================================================
-- FlightLog — Orion High School Organizational Memory
-- Database Schema  (source of truth — keep this in git)
--
-- Recreates the full database from scratch on any
-- PostgreSQL + pgvector provider (Supabase, Neon, Render, etc.)
--
-- Usage:
--   Supabase SQL Editor: paste entire file, click Run
--   psql: psql $DATABASE_URL -f schema.sql
-- ============================================================

-- ── Extensions ────────────────────────────────────────────────────────────────

create extension if not exists vector;
create extension if not exists "uuid-ossp";

-- ── documents ─────────────────────────────────────────────────────────────────
-- One row per ingested source file.
-- Holds metadata + full extracted text.
-- Embeddings live in chunks (one document → many chunks).

create table if not exists documents (
    id                uuid        primary key default uuid_generate_v4(),
    source_hash       varchar     not null unique,
    source_file       text        not null,
    original_filename text        not null,
    ingested_at       timestamptz not null default now(),
    subject           text,
    school_year       text,
    teacher           text,
    doc_type          text,
    unit              text,
    notes             text,
    raw_text          text
);

comment on table  documents                   is 'One row per ingested source file. Metadata + full extracted text. Embeddings live in chunks.';
comment on column documents.id                is 'UUID primary key, generated automatically';
comment on column documents.source_hash       is 'SHA-256 of the source file bytes — used to skip re-ingestion of unchanged files; if the hash changes, the file has been edited and ingest.py --update will replace it';
comment on column documents.source_file       is 'Absolute path to the source file at time of ingestion (local machine path, not a URL) — used by --update mode to find the old record when a file changes';
comment on column documents.original_filename is 'Bare filename with no path — used for display in search results and source citations';
comment on column documents.ingested_at       is 'UTC timestamp when this document was first added to FlightLog — set once on INSERT, never updated';
comment on column documents.subject           is 'Broad subject area: All-Staff, Physics, Economics, Engineering, etc. — set via --subject flag in ingest.py; used for search filtering';
comment on column documents.school_year       is 'School year string: pre-opening, 2024-25, 2025-26, etc. — auto-detected from email sent date for .msg files; set via --year flag for other types';
comment on column documents.teacher           is 'Author or sender — auto-detected from email From: header for .msg files; set via --teacher flag for other types';
comment on column documents.doc_type          is 'Document category: email | lesson_plan | meeting_notes | policy | other — drives filtering in FlightLog search UI and dashboard';
comment on column documents.unit              is 'Sub-topic or source group: auto-detected from SharePoint subfolder name in batch mode, or set via --unit flag (e.g. "Orion Planning Team", "Bell Schedules")';
comment on column documents.notes             is 'Free-text provenance notes added at ingest time — e.g. attachment manifest for emails, or context about why a standalone file was added outside normal batch ingestion';
comment on column documents.raw_text          is 'Full extracted text of the source document — stored so the corpus can be re-embedded with better models in the future without re-reading original files; not queried directly (chunks table holds the searchable embeddings)';

-- ── chunks ────────────────────────────────────────────────────────────────────
-- Every document is split into overlapping text windows at two sizes.
-- Both sizes are stored for every document so searches can trade off
-- precision (small) vs. context (large) at query time.

create table if not exists chunks (
    id          bigint      primary key generated always as identity,
    document_id uuid        not null references documents(id) on delete cascade,
    chunk_size  text        not null check (chunk_size in ('small', 'large')),
    content     text        not null,
    embedding   vector(1536),
    position    int         not null,
    token_count int
);

comment on table  chunks              is 'Chunked + embedded text from every document. Two rows per logical chunk (small and large). This is what pgvector similarity search queries.';
comment on column chunks.document_id  is 'FK to documents.id — every chunk belongs to exactly one source document; cascade delete keeps chunks in sync if a document is removed';
comment on column chunks.chunk_size   is 'small (~200 tokens, 50-token overlap) or large (~900 tokens, 100-token overlap) — both are stored for every document to support precision-vs-context tradeoff at search time';
comment on column chunks.content      is 'The actual text of this chunk — what gets returned to the user in search results and fed to Claude Haiku for answer synthesis';
comment on column chunks.embedding    is '1536-dimensional pgvector embedding produced by OpenAI text-embedding-3-small — the heart of semantic similarity search; can be regenerated from content if model changes';
comment on column chunks.position     is 'Zero-based index of this chunk within its document — preserves reading order; used for context reconstruction if adjacent chunks are needed';
comment on column chunks.token_count  is 'Number of tiktoken cl100k_base tokens in this chunk — used for cost estimation and for verifying chunk size targets were hit during ingestion';

-- ── query_log ─────────────────────────────────────────────────────────────────
-- Every search query submitted through the FlightLog web UI.
-- Low-hit queries (result_count < 3) surface knowledge gaps —
-- topics that staff are asking about but that are not yet in the archive.

create table if not exists query_log (
    id           bigint      primary key generated always as identity,
    query_text   text        not null,
    result_count int         not null default 0,
    had_answer   boolean     default false,
    queried_at   timestamptz default now()
);

comment on table  query_log              is 'Every search query submitted through the FlightLog web UI. Low-hit queries (result_count < 3) surface topics that staff care about but are not yet archived.';
comment on column query_log.query_text   is 'The raw query string exactly as typed by the user';
comment on column query_log.result_count is 'Number of chunks returned by vector search — 0 or 1-2 results indicate a knowledge gap worth filling';
comment on column query_log.had_answer   is 'True if Claude Haiku synthesis ran (i.e. the Ask tab was used); false if the user only used raw search view';
comment on column query_log.queried_at   is 'UTC timestamp of the query — used for trending and activity reporting in dashboard.php';

-- ── Indexes ───────────────────────────────────────────────────────────────────

-- HNSW index: fast approximate nearest-neighbor search on embeddings.
-- m=16, ef_construction=64 are Supabase defaults; tune ef_search at query time.
-- If your pgvector provider does not support HNSW, replace with:
--   create index ... using ivfflat (embedding vector_cosine_ops) with (lists = 100);
create index if not exists chunks_embedding_hnsw
    on chunks using hnsw (embedding vector_cosine_ops)
    with (m = 16, ef_construction = 64);

create index if not exists chunks_document_id_idx on chunks    (document_id);
create index if not exists documents_hash_idx     on documents (source_hash);
create index if not exists documents_type_idx     on documents (doc_type);
create index if not exists documents_unit_idx     on documents (unit);
create index if not exists query_log_queried_idx  on query_log (queried_at desc);

-- ── RPC: search_ohs_memory ────────────────────────────────────────────────────
-- Called by search-proxy.php on every FlightLog query.
-- Returns ranked chunks with their parent document metadata.

create or replace function search_ohs_memory(
    query_embedding   vector(1536),
    match_count       int     default 8,
    filter_subject    text    default null,
    filter_year       text    default null,
    filter_doc_type   text    default null,
    filter_chunk_size text    default null,
    min_similarity    float   default 0.40
)
returns table (
    id                uuid,
    document_id       uuid,
    chunk_size        text,
    content           text,
    similarity        float,
    original_filename text,
    source_file       text,
    subject           text,
    school_year       text,
    teacher           text,
    doc_type          text,
    unit              text,
    notes             text
)
language sql stable as $$
    select
        c.id, c.document_id, c.chunk_size, c.content,
        1 - (c.embedding <=> query_embedding) as similarity,
        d.original_filename, d.source_file,
        d.subject, d.school_year, d.teacher, d.doc_type, d.unit, d.notes
    from chunks c
    join documents d on d.id = c.document_id
    where
        (filter_subject    is null or d.subject     ilike '%' || filter_subject    || '%')
    and (filter_year       is null or d.school_year =            filter_year)
    and (filter_doc_type   is null or d.doc_type    =            filter_doc_type)
    and (filter_chunk_size is null or c.chunk_size  =            filter_chunk_size)
    and (1 - (c.embedding <=> query_embedding))     >=           min_similarity
    order by c.embedding <=> query_embedding
    limit match_count;
$$;

-- ── RPC: list_ohs_documents ───────────────────────────────────────────────────
-- Called by list-proxy.php to populate the Flight Records archive browser tab.

create or replace function list_ohs_documents(
    filter_subject  text default null,
    filter_year     text default null,
    filter_doc_type text default null
)
returns table (
    id                uuid,
    original_filename text,
    subject           text,
    school_year       text,
    teacher           text,
    doc_type          text,
    unit              text,
    ingested_at       timestamptz
)
language sql stable as $$
    select id, original_filename, subject, school_year, teacher,
           doc_type, unit, ingested_at
    from documents
    where
        (filter_subject  is null or subject     ilike '%' || filter_subject  || '%')
    and (filter_year     is null or school_year =           filter_year)
    and (filter_doc_type is null or doc_type    =           filter_doc_type)
    order by ingested_at desc;
$$;

-- ── RPC: flightlog_stats ──────────────────────────────────────────────────────
-- Called by dashboard.php. Returns all stats in one round trip.
-- security definer = runs as table owner, bypasses RLS so anon key can read it.

create or replace function flightlog_stats()
returns json language sql security definer as $$
  select json_build_object(

    'docs_total',    (select count(*) from documents where doc_type != 'email'),
    'email_total',   (select count(*) from documents where doc_type  = 'email'),
    'chunks_total',  (select count(*) from chunks),
    'total_queries', (select count(*) from query_log),
    'zero_hit_pct',  (
      select case when count(*) = 0 then 0
             else round(100.0 * sum(case when result_count = 0 then 1 else 0 end) / count(*))
             end
      from query_log
    ),

    'storage', (
      select json_build_object(
        'documents_table', pg_size_pretty(pg_total_relation_size('documents')),
        'chunks_table',    pg_size_pretty(pg_total_relation_size('chunks')),
        'database_total',  pg_size_pretty(pg_database_size(current_database())),
        'free_tier_mb',    round(pg_database_size(current_database()) / 1024.0 / 1024.0),
        'free_tier_pct',   round(100.0 * pg_database_size(current_database()) / (500 * 1024 * 1024))
      )
    ),

    'docs_by_unit', (
      select json_agg(r) from (
        select coalesce(unit,'(untagged)') as unit, count(*) as n
        from documents where doc_type != 'email'
        group by 1 order by 2 desc limit 15
      ) r
    ),

    'docs_by_week', (
      select json_agg(r) from (
        select to_char(date_trunc('week', ingested_at),'Mon DD') as week,
               date_trunc('week', ingested_at) as sort_key,
               count(*) as n
        from documents where doc_type != 'email'
        group by 1,2 order by 2 desc limit 8
      ) r
    ),

    'emails_by_year', (
      select json_agg(r) from (
        select coalesce(school_year,'unknown') as yr, count(*) as n
        from documents where doc_type = 'email'
        group by 1 order by 1
      ) r
    ),

    'emails_by_week', (
      select json_agg(r) from (
        select to_char(date_trunc('week', ingested_at),'Mon DD') as week,
               date_trunc('week', ingested_at) as sort_key,
               count(*) as n
        from documents where doc_type = 'email'
        group by 1,2 order by 2 desc limit 8
      ) r
    ),

    'low_hit_queries', (
      select json_agg(r) from (
        select query_text                       as query,
               round(avg(result_count))::int    as avg_hits,
               count(*)                         as times_asked,
               max(queried_at)                  as last_asked
        from query_log
        where result_count < 3
        group by query_text
        order by times_asked desc, last_asked desc
        limit 30
      ) r
    )

  );
$$;

-- ============================================================
-- RCW/WAC Legal RAG — Database Schema
--
-- Lives in the SAME Supabase project as OHS Memory.
-- Tables are prefixed rcw_ to avoid name collisions.
--
-- Usage:
--   Supabase SQL Editor: paste entire file, click Run
--   psql: psql $DATABASE_URL -f schema.sql
-- ============================================================

-- ── Extensions (already enabled by OHS Memory, included for safety) ──────────

create extension if not exists vector;

-- ── rcw_wac_chunks ────────────────────────────────────────────────────────────
-- One row per text chunk. Most RCW/WAC sections fit in a single chunk;
-- long sections are split with 100-token overlap at ingest time.
-- The section_id + chunk_index pair is the natural unique key.
--
-- Design note: we intentionally skip a separate "documents" table.
-- RCW/WAC sections are already the natural document unit. One table is simpler
-- and sufficient for this read-mostly, one-time-ingest use case.

create table if not exists rcw_wac_chunks (
    id              bigint      primary key generated always as identity,
    corpus          text        not null check (corpus in ('rcw', 'wac')),
    title_num       text        not null,   -- '28A', '392', etc.
    title_name      text,                   -- 'Common School Provisions'
    chapter_num     text        not null,   -- '28A.400', '392-121', etc.
    chapter_name    text,                   -- 'Employees — General'
    section_num     text        not null,   -- '010', '122', etc.
    section_id      text        not null,   -- 'RCW 28A.400.010' | 'WAC 392-121-122'
    section_heading text,                   -- 'Certificated employee contracts'
    chunk_index     int         not null default 0,
    content         text        not null,   -- section_id + heading prefix + body text
    token_count     int,
    source_url      text,
    content_hash    text        not null,   -- SHA-256 truncated to 32 chars for dedup
    embedding       vector(1536),           -- OpenAI text-embedding-3-small
    fts             tsvector    generated always as (
                        to_tsvector('english',
                            coalesce(section_id, '') || ' ' ||
                            coalesce(section_heading, '') || ' ' ||
                            coalesce(content, '')
                        )
                    ) stored,
    ingested_at     timestamptz default now(),

    unique (section_id, chunk_index)
);

comment on table  rcw_wac_chunks                  is 'One chunk per RCW/WAC section (long sections split). Contains embedding + full-text index for hybrid vector+BM25 search.';
comment on column rcw_wac_chunks.corpus           is '''rcw'' = Revised Code of Washington (statute), ''wac'' = Washington Administrative Code (administrative rule)';
comment on column rcw_wac_chunks.section_id       is 'Canonical citation: ''RCW 28A.400.010'' or ''WAC 392-121-122''';
comment on column rcw_wac_chunks.chunk_index      is '0-based. Most sections = single chunk (index 0). Long sections are split; each chunk includes the section_id prefix for context.';
comment on column rcw_wac_chunks.content          is 'Embedded text: ''RCW 28A.400.010 — Heading\n\nBody text...''. Heading prefix on every chunk ensures context survives chunking.';
comment on column rcw_wac_chunks.embedding        is '1536-dim OpenAI text-embedding-3-small. Regenerate from content if model changes.';
comment on column rcw_wac_chunks.fts              is 'GIN-indexed tsvector covering section_id + heading + content. Enables BM25 keyword lane for hybrid search.';

-- ── rcw_wac_query_log ─────────────────────────────────────────────────────────

create table if not exists rcw_wac_query_log (
    id           bigint      primary key generated always as identity,
    query_text   text        not null,
    corpus_filter text,                 -- 'rcw' | 'wac' | null for both
    result_count int         not null default 0,
    queried_at   timestamptz default now()
);

comment on table rcw_wac_query_log is 'Every query submitted through the RCW/WAC chat UI. Zero-hit queries reveal coverage gaps.';

-- ── Indexes ───────────────────────────────────────────────────────────────────

-- HNSW for fast ANN search on embeddings
create index if not exists rcw_wac_embedding_hnsw
    on rcw_wac_chunks using hnsw (embedding vector_cosine_ops)
    with (m = 16, ef_construction = 64);

-- GIN for BM25 keyword search
create index if not exists rcw_wac_fts_gin
    on rcw_wac_chunks using gin (fts);

-- Lookup indexes
create index if not exists rcw_wac_corpus_idx  on rcw_wac_chunks (corpus);
create index if not exists rcw_wac_title_idx   on rcw_wac_chunks (title_num);
create index if not exists rcw_wac_hash_idx    on rcw_wac_chunks (content_hash);
create index if not exists rcw_wac_query_idx   on rcw_wac_query_log (queried_at desc);

-- ── RPC: search_rcw_wac ───────────────────────────────────────────────────────
-- Called by api-proxy.php on every chat query.
-- Hybrid search: vector cosine similarity + BM25 keyword, fused via RRF.
-- BM25 lane only activates when query_text is supplied.

create or replace function search_rcw_wac(
    query_embedding  vector(1536),
    match_count      int     default 8,
    min_similarity   float   default 0.25,
    filter_corpus    text    default null,   -- 'rcw' | 'wac' | null
    filter_title     text    default null,   -- e.g. '28A'
    query_text       text    default null    -- enables BM25 lane when supplied
)
returns table (
    id              bigint,
    corpus          text,
    section_id      text,
    section_heading text,
    title_name      text,
    chapter_name    text,
    chunk_index     int,
    content         text,
    source_url      text,
    similarity      float
)
language sql stable security definer as $$
    with
    -- ── Vector lane ───────────────────────────────────────────────────────────
    vec as (
        select
            c.id                                                        as cid,
            row_number() over (order by c.embedding <=> query_embedding) as rnk,
            (1 - (c.embedding <=> query_embedding))::float              as sim
        from rcw_wac_chunks c
        where
            c.embedding is not null
            and (filter_corpus is null or c.corpus   = filter_corpus)
            and (filter_title  is null or c.title_num = filter_title)
            and (1 - (c.embedding <=> query_embedding)) >= min_similarity
        order by c.embedding <=> query_embedding
        limit match_count * 10
    ),
    -- ── BM25 lane (activated when query_text is supplied) ─────────────────────
    -- OR-semantics across stemmed lexemes prevents AND-requiring misses.
    bm25 as (
        select
            c.id                                                        as cid,
            row_number() over (order by ts_rank_cd(c.fts, tsq.q) desc)  as rnk
        from rcw_wac_chunks c,
             lateral (
                 select to_tsquery('english',
                     array_to_string(
                         array(select lexeme from unnest(to_tsvector('english', query_text))),
                         ' | '
                     )
                 ) as q
             ) tsq
        where
            query_text is not null
            and c.fts @@ tsq.q
            and (filter_corpus is null or c.corpus   = filter_corpus)
            and (filter_title  is null or c.title_num = filter_title)
        order by ts_rank_cd(c.fts, tsq.q) desc
        limit match_count * 10
    ),
    -- ── Reciprocal Rank Fusion (k=60) ─────────────────────────────────────────
    fused as (
        select
            coalesce(v.cid, b.cid)                         as cid,
            coalesce(1.0 / (60 + v.rnk), 0::float) +
            coalesce(1.0 / (60 + b.rnk), 0::float)        as rrf_score,
            coalesce(v.sim, 0::float)                      as similarity
        from vec v
        full outer join bm25 b on b.cid = v.cid
    )
    select
        c.id, c.corpus, c.section_id, c.section_heading,
        c.title_name, c.chapter_name, c.chunk_index,
        c.content, c.source_url, f.similarity
    from fused f
    join rcw_wac_chunks c on c.id = f.cid
    order by f.rrf_score desc
    limit match_count;
$$;

-- ── RPC: rcw_wac_stats ────────────────────────────────────────────────────────
-- Quick corpus stats for admin/debugging. security definer so anon key works.

create or replace function rcw_wac_stats()
returns json language sql security definer as $$
    select json_build_object(
        'rcw_chunks', (select count(*) from rcw_wac_chunks where corpus = 'rcw'),
        'wac_chunks', (select count(*) from rcw_wac_chunks where corpus = 'wac'),
        'total_chunks', (select count(*) from rcw_wac_chunks),
        'rcw_titles', (select count(distinct title_num) from rcw_wac_chunks where corpus = 'rcw'),
        'wac_titles', (select count(distinct title_num) from rcw_wac_chunks where corpus = 'wac'),
        'total_queries', (select count(*) from rcw_wac_query_log),
        'zero_hit_queries', (select count(*) from rcw_wac_query_log where result_count = 0)
    );
$$;

-- ── Row Level Security ────────────────────────────────────────────────────────
-- Security model:
--   anon key (PHP proxy)    → can call RPCs, can INSERT to query_log, nothing else
--   service_role key (ingest.py) → bypasses RLS, full INSERT access to chunks
--
-- This means:
--   - Nobody can dump the chunks table directly via the anon key
--   - All read access goes through search_rcw_wac() which is SECURITY DEFINER
--   - The ingest Python script uses the service_role key, kept off the server
--
-- Note: search_rcw_wac() is promoted to SECURITY DEFINER so it can read chunks
-- even though the anon key has no direct SELECT on the table.

alter table public.rcw_wac_chunks    enable row level security;
alter table public.rcw_wac_query_log enable row level security;

-- rcw_wac_chunks: no direct anon access.
-- Read goes through search_rcw_wac() (SECURITY DEFINER).
-- Write goes through ingest.py using service_role key (bypasses RLS).

-- rcw_wac_query_log: anon INSERT only for query logging from the PHP proxy.
-- No SELECT policy = anon cannot read query history.
drop policy if exists "allow_insert" on public.rcw_wac_query_log;
create policy "allow_insert" on public.rcw_wac_query_log
    for insert to anon with check (true);

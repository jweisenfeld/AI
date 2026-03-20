-- OHS Organizational Memory — PostgreSQL + pgvector Schema
-- Run this in Supabase SQL Editor: Dashboard → SQL Editor → paste → Run
-- Supabase has pgvector enabled by default. Nothing extra to install.

-- ─────────────────────────────────────────────
-- Extensions
-- ─────────────────────────────────────────────
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ─────────────────────────────────────────────
-- documents: one row per source file ingested
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id                UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    source_hash       VARCHAR(64) UNIQUE NOT NULL,   -- SHA-256 of raw file bytes (dedup)
    source_file       TEXT        NOT NULL,
    original_filename TEXT        NOT NULL,
    ingested_at       TIMESTAMPTZ DEFAULT NOW(),

    -- Metadata (used for filtered searches)
    subject           TEXT,       -- 'Economics', 'Physics', 'All-Staff'
    school_year       TEXT,       -- '2025-26'
    teacher           TEXT,       -- 'Weisenfeld'
    doc_type          TEXT CHECK (doc_type IN (
                          'lesson_plan', 'meeting_notes', 'policy', 'email', 'other'
                      )),
    unit              TEXT,       -- 'Supply and Demand'
    notes             TEXT,

    -- Raw extracted text — stored so we can re-embed later with better models
    -- without reprocessing the original PDFs
    raw_text          TEXT NOT NULL
);

-- ─────────────────────────────────────────────
-- chunks: many rows per document, two sizes
-- small (~200 tokens): precise factual retrieval
-- large (~900 tokens): contextual retrieval
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chunks (
    id          UUID    PRIMARY KEY DEFAULT uuid_generate_v4(),
    document_id UUID    NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    chunk_size  TEXT    NOT NULL CHECK (chunk_size IN ('small', 'large')),
    content     TEXT    NOT NULL,

    -- 1536-dimensional embeddings from OpenAI text-embedding-3-small
    embedding   vector(1536),

    position    INTEGER NOT NULL,
    token_count INTEGER
);

-- ─────────────────────────────────────────────
-- Indexes
-- Using HNSW (better than IVFFlat: no training data needed, higher recall)
-- ─────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS chunks_embedding_hnsw_idx
    ON chunks USING hnsw (embedding vector_cosine_ops);

CREATE INDEX IF NOT EXISTS chunks_document_id_idx ON chunks (document_id);
CREATE INDEX IF NOT EXISTS chunks_chunk_size_idx  ON chunks (chunk_size);
CREATE INDEX IF NOT EXISTS documents_subject_idx  ON documents (subject);
CREATE INDEX IF NOT EXISTS documents_year_idx     ON documents (school_year);
CREATE INDEX IF NOT EXISTS documents_type_idx     ON documents (doc_type);
CREATE INDEX IF NOT EXISTS documents_teacher_idx  ON documents (teacher);

-- ─────────────────────────────────────────────
-- Row Level Security
-- Enable RLS but allow anonymous SELECT (read-only public search)
-- The anon key can read; only your service_role key can write.
-- ─────────────────────────────────────────────
ALTER TABLE documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE chunks    ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Allow public read on documents"
    ON documents FOR SELECT USING (true);

CREATE POLICY "Allow public read on chunks"
    ON chunks FOR SELECT USING (true);

-- Service role can do everything (used by ingest.py via DATABASE_URL)
CREATE POLICY "Service role full access to documents"
    ON documents FOR ALL USING (auth.role() = 'service_role');

CREATE POLICY "Service role full access to chunks"
    ON chunks FOR ALL USING (auth.role() = 'service_role');

-- ─────────────────────────────────────────────
-- search_ohs_memory() — the core search function
-- Called by search-proxy.php via Supabase REST API:
--   POST /rest/v1/rpc/search_ohs_memory
-- This keeps the PHP side simple: just send the embedding vector,
-- get back ranked results. No complex SQL in PHP.
-- ─────────────────────────────────────────────
CREATE OR REPLACE FUNCTION search_ohs_memory(
    query_embedding  vector(1536),
    filter_subject   text    DEFAULT NULL,
    filter_year      text    DEFAULT NULL,
    filter_doc_type  text    DEFAULT NULL,
    filter_teacher   text    DEFAULT NULL,
    filter_chunk_size text   DEFAULT NULL,  -- 'small', 'large', or NULL (both)
    match_count      integer DEFAULT 6
)
RETURNS TABLE (
    content           text,
    chunk_size        text,
    token_count       integer,
    similarity        float,
    original_filename text,
    subject           text,
    school_year       text,
    teacher           text,
    doc_type          text,
    unit              text
)
LANGUAGE sql STABLE SECURITY DEFINER
AS $$
    SELECT
        c.content,
        c.chunk_size,
        c.token_count,
        (1 - (c.embedding <=> query_embedding))::float AS similarity,
        d.original_filename,
        d.subject,
        d.school_year,
        d.teacher,
        d.doc_type,
        d.unit
    FROM chunks c
    JOIN documents d ON c.document_id = d.id
    WHERE
        (filter_subject    IS NULL OR d.subject    ILIKE '%' || filter_subject    || '%')
        AND (filter_year   IS NULL OR d.school_year =         filter_year)
        AND (filter_doc_type IS NULL OR d.doc_type  =         filter_doc_type)
        AND (filter_teacher  IS NULL OR d.teacher   ILIKE '%' || filter_teacher  || '%')
        AND (filter_chunk_size IS NULL OR c.chunk_size = filter_chunk_size)
    ORDER BY c.embedding <=> query_embedding
    LIMIT match_count;
$$;

-- Grant execute on the function to the anon role (needed for REST API calls)
GRANT EXECUTE ON FUNCTION search_ohs_memory TO anon;
GRANT EXECUTE ON FUNCTION search_ohs_memory TO authenticated;

-- ─────────────────────────────────────────────
-- list_ohs_documents() — for the document browser
-- ─────────────────────────────────────────────
CREATE OR REPLACE FUNCTION list_ohs_documents(
    filter_subject  text DEFAULT NULL,
    filter_year     text DEFAULT NULL,
    filter_doc_type text DEFAULT NULL
)
RETURNS TABLE (
    id                uuid,
    original_filename text,
    subject           text,
    school_year       text,
    teacher           text,
    doc_type          text,
    unit              text,
    notes             text,
    ingested_at       text,
    chunk_count       bigint
)
LANGUAGE sql STABLE SECURITY DEFINER
AS $$
    SELECT
        d.id,
        d.original_filename,
        d.subject,
        d.school_year,
        d.teacher,
        d.doc_type,
        d.unit,
        d.notes,
        d.ingested_at::text,
        COUNT(DISTINCT c.id) AS chunk_count
    FROM documents d
    LEFT JOIN chunks c ON c.document_id = d.id AND c.chunk_size = 'small'
    WHERE
        (filter_subject  IS NULL OR d.subject     ILIKE '%' || filter_subject  || '%')
        AND (filter_year IS NULL OR d.school_year =           filter_year)
        AND (filter_doc_type IS NULL OR d.doc_type =          filter_doc_type)
    GROUP BY d.id
    ORDER BY d.school_year DESC NULLS LAST, d.subject, d.original_filename;
$$;

GRANT EXECUTE ON FUNCTION list_ohs_documents TO anon;
GRANT EXECUTE ON FUNCTION list_ohs_documents TO authenticated;

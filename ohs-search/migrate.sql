-- OHS Memory — One-shot timeout fix
-- Paste this into the Supabase SQL Editor and click Run.
-- This re-declares the search function so it manages its own 30-second
-- timeout, which is required because the function uses SECURITY DEFINER
-- (it runs as the function owner, not as the anon role).

CREATE OR REPLACE FUNCTION search_ohs_memory(
    query_embedding  vector(1536),
    filter_subject   text    DEFAULT NULL,
    filter_year      text    DEFAULT NULL,
    filter_doc_type  text    DEFAULT NULL,
    filter_teacher   text    DEFAULT NULL,
    filter_chunk_size text   DEFAULT NULL,
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
SET statement_timeout = '30s'
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

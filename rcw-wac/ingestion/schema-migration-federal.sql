-- ============================================================
-- Schema migration: add USC and CFR corpus support
--
-- Run once in the Supabase SQL Editor for the rcw-wac project.
-- Safe to re-run (all statements are idempotent).
-- ============================================================

-- ── 1. Expand the corpus CHECK constraint ─────────────────────────────────────

ALTER TABLE public.rcw_wac_chunks
    DROP CONSTRAINT IF EXISTS rcw_wac_chunks_corpus_check;

ALTER TABLE public.rcw_wac_chunks
    ADD CONSTRAINT rcw_wac_chunks_corpus_check
    CHECK (corpus IN ('rcw', 'wac', 'usc', 'cfr'));


-- ── 2. Update search_rcw_wac to handle group filters ─────────────────────────
-- New filter values:
--   'state'   → RCW + WAC
--   'federal' → USC + CFR
--   'rcw'|'wac'|'usc'|'cfr' → single corpus (unchanged)
--   null → all corpora

CREATE OR REPLACE FUNCTION search_rcw_wac(
    query_embedding  vector(1536),
    match_count      int     default 8,
    min_similarity   float   default 0.25,
    filter_corpus    text    default null,
    filter_title     text    default null,
    query_text       text    default null
)
RETURNS TABLE (
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
LANGUAGE sql STABLE SECURITY DEFINER AS $$
    WITH
    vec AS (
        SELECT
            c.id                                                        AS cid,
            row_number() OVER (ORDER BY c.embedding <=> query_embedding) AS rnk,
            (1 - (c.embedding <=> query_embedding))::float              AS sim
        FROM rcw_wac_chunks c
        WHERE
            c.embedding IS NOT NULL
            AND (
                filter_corpus IS NULL
                OR c.corpus = filter_corpus
                OR (filter_corpus = 'state'   AND c.corpus IN ('rcw', 'wac'))
                OR (filter_corpus = 'federal' AND c.corpus IN ('usc', 'cfr'))
            )
            AND (filter_title IS NULL OR c.title_num = filter_title)
            AND (1 - (c.embedding <=> query_embedding)) >= min_similarity
        ORDER BY c.embedding <=> query_embedding
        LIMIT match_count * 10
    ),
    bm25 AS (
        SELECT
            c.id                                                        AS cid,
            row_number() OVER (ORDER BY ts_rank_cd(c.fts, tsq.q) DESC)  AS rnk
        FROM rcw_wac_chunks c,
             LATERAL (
                 SELECT to_tsquery('english',
                     array_to_string(
                         array(SELECT lexeme FROM unnest(to_tsvector('english', query_text))),
                         ' | '
                     )
                 ) AS q
             ) tsq
        WHERE
            query_text IS NOT NULL
            AND c.fts @@ tsq.q
            AND (
                filter_corpus IS NULL
                OR c.corpus = filter_corpus
                OR (filter_corpus = 'state'   AND c.corpus IN ('rcw', 'wac'))
                OR (filter_corpus = 'federal' AND c.corpus IN ('usc', 'cfr'))
            )
            AND (filter_title IS NULL OR c.title_num = filter_title)
        ORDER BY ts_rank_cd(c.fts, tsq.q) DESC
        LIMIT match_count * 10
    ),
    fused AS (
        SELECT
            coalesce(v.cid, b.cid)                         AS cid,
            coalesce(1.0 / (60 + v.rnk), 0::float) +
            coalesce(1.0 / (60 + b.rnk), 0::float)        AS rrf_score,
            coalesce(v.sim, 0::float)                      AS similarity
        FROM vec v
        FULL OUTER JOIN bm25 b ON b.cid = v.cid
    )
    SELECT
        c.id, c.corpus, c.section_id, c.section_heading,
        c.title_name, c.chapter_name, c.chunk_index,
        c.content, c.source_url, f.similarity
    FROM fused f
    JOIN rcw_wac_chunks c ON c.id = f.cid
    ORDER BY f.rrf_score DESC
    LIMIT match_count;
$$;


-- ── 3. Update rcw_wac_stats to include federal law counts ─────────────────────

CREATE OR REPLACE FUNCTION rcw_wac_stats()
RETURNS json LANGUAGE sql SECURITY DEFINER AS $$
    SELECT json_build_object(
        'rcw_chunks',     (SELECT count(*) FROM rcw_wac_chunks WHERE corpus = 'rcw'),
        'wac_chunks',     (SELECT count(*) FROM rcw_wac_chunks WHERE corpus = 'wac'),
        'usc_chunks',     (SELECT count(*) FROM rcw_wac_chunks WHERE corpus = 'usc'),
        'cfr_chunks',     (SELECT count(*) FROM rcw_wac_chunks WHERE corpus = 'cfr'),
        'total_chunks',   (SELECT count(*) FROM rcw_wac_chunks),
        'rcw_titles',     (SELECT count(DISTINCT title_num) FROM rcw_wac_chunks WHERE corpus = 'rcw'),
        'wac_titles',     (SELECT count(DISTINCT title_num) FROM rcw_wac_chunks WHERE corpus = 'wac'),
        'total_queries',  (SELECT count(*) FROM rcw_wac_query_log),
        'zero_hit_queries', (SELECT count(*) FROM rcw_wac_query_log WHERE result_count = 0)
    );
$$;

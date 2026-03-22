-- ============================================================================
-- FlightLog — Email Ingestion API Function
-- ============================================================================
-- Called by ingest-email-proxy.php to insert a single email (document + all
-- chunks) in one atomic transaction.  The vector cast ( ::vector ) must happen
-- inside Postgres because the pgvector text format is not valid JSON, so we
-- cannot let PostgREST try to do the column mapping for us.
--
-- Usage: run this in the Supabase SQL Editor, then deploy ingest-email-proxy.php
--
-- Parameters:
--   p_source_hash  — SHA-256 of the full email text (dedup key)
--   p_source_file  — "outlook:<sender>:<date>" (provenance string)
--   p_filename     — "<subject>.msg" (display name in FlightLog)
--   p_school_year  — "2025-26", "pre-opening", etc.
--   p_teacher      — sender display name
--   p_unit         — e.g. "Orion Planning Team"
--   p_notes        — free-form provenance note
--   p_raw_text     — full extracted email text
--   p_chunks       — JSONB array of chunk objects:
--                    [{ "size": "small"|"large",
--                       "text": "...",
--                       "embedding": "[0.1,0.2,...,1536 values]",
--                       "position": 0,
--                       "tokens": 145 }, ...]
--
-- Returns: JSONB
--   { "ok": true,  "doc_id": "uuid", "chunks": N }         — new document
--   { "ok": false, "skipped": true,  "doc_id": "uuid" }    — already in DB
-- ============================================================================

create or replace function insert_email_document(
    p_source_hash  text,
    p_source_file  text,
    p_filename     text,
    p_school_year  text,
    p_teacher      text,
    p_unit         text,
    p_notes        text,
    p_raw_text     text,
    p_chunks       jsonb
)
returns jsonb
language plpgsql
as $$
declare
    v_doc_id   uuid;
    v_chunk    jsonb;
    v_count    int := 0;
begin
    -- ── Deduplication ─────────────────────────────────────────────────────────
    select id into v_doc_id
    from documents
    where source_hash = p_source_hash;

    if found then
        return jsonb_build_object(
            'ok',      false,
            'skipped', true,
            'doc_id',  v_doc_id
        );
    end if;

    -- ── Insert document record ────────────────────────────────────────────────
    insert into documents (
        source_hash, source_file, original_filename,
        school_year, teacher, doc_type, unit, notes, raw_text
    )
    values (
        p_source_hash, p_source_file, p_filename,
        p_school_year, p_teacher, 'email', p_unit, p_notes, p_raw_text
    )
    returning id into v_doc_id;

    -- ── Insert chunks with vector casting ─────────────────────────────────────
    -- The embedding arrives as the pgvector text format "[x,y,...,z]".
    -- The ::vector cast here is the reason this lives in a SQL function —
    -- PostgREST cannot do this cast automatically via the REST API.
    for v_chunk in select * from jsonb_array_elements(p_chunks) loop
        insert into chunks (
            document_id, chunk_size, content,
            embedding, position, token_count
        )
        values (
            v_doc_id,
            v_chunk->>'size',
            v_chunk->>'text',
            (v_chunk->>'embedding')::vector,
            (v_chunk->>'position')::int,
            coalesce((v_chunk->>'tokens')::int, 0)
        );
        v_count := v_count + 1;
    end loop;

    return jsonb_build_object(
        'ok',     true,
        'doc_id', v_doc_id,
        'chunks', v_count
    );
end;
$$;

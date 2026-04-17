# WA Law RAG Chat — How It Works

A retrieval-augmented generation (RAG) chat interface for Washington State and federal law. Users ask plain-language questions and receive answers grounded in specific RCW, WAC, USC, and CFR sections with clickable citations to the official sources.

**Live demo:** https://psd1.net/rcw-wac/  
**LEO derivative:** https://psd1.net/rcw-wac-leo/ (tuned for law enforcement)

---

## Architecture

```
User question (any language)
        │
        ▼
api-proxy.php
  ├─ 1. Embed query — OpenAI text-embedding-3-small → 1536-dim vector
  ├─ 2. Search Supabase — search_rcw_wac() RPC
  │       vector cosine (HNSW) + BM25 full-text
  │       Reciprocal Rank Fusion k=60, top 8 results
  ├─ 3. SSE event: {"sources": [...]}   ← citation cards render immediately
  ├─ 4. Build system prompt: SYSTEM_PROMPT + retrieved sections as {CONTEXT}
  └─ 5. Stream Claude Haiku → SSE {"text":"..."} deltas → browser renders markdown
```

Round-trip latency: ~1 s for embed + search, then streaming starts.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | Vanilla HTML/CSS/JS, marked.js (markdown rendering) |
| API proxy | PHP 8.x — server-side only, keeps keys off the client |
| Vector store | Supabase (Postgres + pgvector extension) |
| Embedding | OpenAI `text-embedding-3-small` — 1536 dimensions |
| LLM | Anthropic Claude Haiku `claude-haiku-4-5-20251001` |
| Ingestion | Python 3.11 — one-time CLI script |

---

## Files

| File | Role |
|------|------|
| `index.html` | Chat UI: corpus toggle, example chips, SSE stream handler, source cards |
| `api-proxy.php` | Embed → search → emit sources → stream Claude |
| `src/RcwWacProxy.php` | Shared class: `getEmbedding()`, `searchSupabase()`, `buildContext()`, `logQuery()` |
| `ingestion/schema.sql` | Supabase schema, RPC functions, indexes |
| `ingestion/ingest.py` | CLI ingestion: crawl → chunk → embed → upsert |
| `ingestion/status.py` | Progress tracker: compare DB against legislature chapter list |
| `.htaccess` | Disables LiteSpeed gzip so SSE streaming works |

---

## Database Schema (Supabase)

**`rcw_wac_chunks`** — one row per law section chunk:

| Column | Type | Notes |
|--------|------|-------|
| `corpus` | text | `rcw` \| `wac` \| `usc` \| `cfr` |
| `title_num` | text | e.g. `28A`, `392`, `20` |
| `chapter_num` | text | e.g. `28A.155`, `392-172A` |
| `section_id` | text | e.g. `RCW 28A.155.020` |
| `section_heading` | text | section title |
| `content` | text | full section text |
| `embedding` | vector(1536) | OpenAI embedding |
| `fts` | tsvector | GENERATED from section_id + heading + content |
| `content_hash` | text | SHA-256 for deduplication |

**Key RPC functions** (run `ingestion/schema.sql` to create):
- `search_rcw_wac(query_embedding, filter_corpus, match_count, min_similarity, query_text)` — hybrid vector+BM25 search with RRF
- `rcw_wac_stats()` — aggregate chunk/title counts per corpus
- `rcw_wac_catalog(filter_corpus)` — title/chapter list for a corpus

---

## Setup

### 1. Supabase project

1. Create a free project at supabase.com
2. Enable the `vector` extension: Database → Extensions → search "vector" → Enable
3. Run `ingestion/schema.sql` in the SQL Editor
4. Fix role timeouts (required — default 8 s kills HNSW search and large ingests):

```sql
ALTER ROLE anon         SET statement_timeout = '30000';
ALTER ROLE authenticator SET statement_timeout = '30000';
ALTER ROLE service_role SET statement_timeout = '0';
```

### 2. Secrets file (on the web server, never committed)

Create `~/.secrets/rcwkey.php`:

```php
<?php
return [
    'ANTHROPIC_API_KEY' => 'sk-ant-...',
    'OPENAI_API_KEY'    => 'sk-...',
    'SUPABASE_URL'      => 'https://yourproject.supabase.co',
    'SUPABASE_ANON_KEY' => 'eyJ...',   // anon key — NOT service_role, NOT sb_publishable_
];
```

> **Key format note:** Use the legacy `eyJ...` JWT anon key, not the newer `sb_publishable_...` format. The new format has origin restrictions that block server-side PHP curl.

### 3. Ingestion

```bash
cd ingestion/
pip install -r requirements.txt

export OPENAI_API_KEY=sk-...
export SUPABASE_URL=https://yourproject.supabase.co
export SUPABASE_SERVICE_KEY=eyJ...   # service_role key for INSERT

# Always dry-run first
python ingest.py --corpus rcw --titles 28A --dry-run

# Then ingest
python ingest.py --corpus rcw --titles 28A         # K-12 education
python ingest.py --corpus wac --titles 392         # OSPI rules
python ingest.py --corpus usc --titles 20          # IDEA (20 USC ch. 33)
python ingest.py --corpus cfr --titles 34          # 34 CFR Part 300

# Check progress
python status.py --corpus rcw
python status.py --corpus wac --missing
```

Ingestion deduplicates by content hash — safe to re-run after interruptions.

### 4. Deploy

Upload to your PHP host: `index.html`, `api-proxy.php`, `src/`, `.htaccess`

---

## Corpus Sources

| Corpus | Source | Method |
|--------|--------|--------|
| RCW | app.leg.wa.gov | XML bulk download per title |
| WAC | app.leg.wa.gov | XML bulk download per title |
| USC | Cornell LII (law.cornell.edu) | HTML scrape per section |
| CFR | eCFR.gov API | Full-title XML (`/api/versioner/v1/full/{date}/title-{n}.xml`) |

---

## Multilingual

Queries work in any language. OpenAI's embedding model maps semantically equivalent text across languages to nearby vectors, so a Spanish question retrieves the same English law sections as the English equivalent. Claude naturally responds in the language of the question.

---

## Regeneration Prompt

Paste this into a fresh Claude conversation to rebuild the project from scratch:

```
Build a legal RAG (Retrieval-Augmented Generation) chat interface for Washington State and federal law.

GOAL: A web app where users ask plain-language questions (in any language) and get answers grounded in specific law sections with clickable citations. Covers RCW, WAC, USC, and CFR.

TECH STACK:
- Supabase (Postgres + pgvector) for vector storage and hybrid search
- OpenAI text-embedding-3-small (1536 dim) for embeddings at ingest and query time
- Anthropic Claude Haiku (claude-haiku-4-5-20251001) for streaming response generation
- PHP 8.x for the server-side SSE proxy (all API keys stay server-side)
- Vanilla HTML/CSS/JS with marked.js for markdown rendering
- Python 3.11 for the one-time ingestion CLI

ARCHITECTURE:
User query → api-proxy.php → OpenAI embed (1536-dim vector) → Supabase hybrid search
(vector cosine via HNSW index + BM25 full-text, combined with Reciprocal Rank Fusion k=60,
top 8 results) → build system prompt with retrieved sections → stream Claude Haiku via
Server-Sent Events → browser renders markdown with source citation cards

FILES TO CREATE:

1. index.html
   - Chat UI with Washington blue color scheme
   - Corpus toggle buttons: All / State (rcw+wac) / RCW / WAC / Federal (usc+cfr)
   - 6-8 example question chips including 2 in Spanish (multilingual showcase)
   - SSE stream handler: receives {sources:[...]}, then {text:"..."} deltas, then {meta:{...}}
   - Renders source citation cards before Claude starts typing (sources arrive first)
   - marked.js for markdown rendering of Claude's response
   - Auto-resizing textarea, Enter to send

2. api-proxy.php
   - Routes: ?stream=1 (main), ?stream=test (SSE sanity check), ?stats=1 (DB counts), ?prompt=1 (system prompt JSON)
   - Validates corpus param: accepts 'rcw', 'wac', 'usc', 'cfr', 'state', 'federal'
   - Calls RcwWacProxy::getEmbedding(), searchSupabase(), buildContext()
   - Emits SSE: first {"sources":[...]}, then Claude streaming deltas, then {"meta":{...}}
   - Heartbeat comment every 4s (": hb") to prevent LiteSpeed idle timeout
   - Loads secrets from ../.secrets/rcwkey.php

3. src/RcwWacProxy.php (namespace RcwWac)
   - getEmbedding(string $text): array — calls OpenAI /v1/embeddings, returns float[]
   - searchSupabase(array $embedding, ?string $corpus, int $limit, ?string $queryText): array
     — calls search_rcw_wac() RPC via Supabase REST
   - buildContext(array $results): array{context: string, sources: list<array>}
     — formats retrieved sections for Claude prompt and lightweight source cards for UI
   - logQuery(string $query, ?string $corpus, int $resultCount): void — fire-and-forget

4. ingestion/schema.sql
   - rcw_wac_chunks table: id, corpus, title_num, title_name, chapter_num, chapter_name,
     section_id, section_heading, content, embedding vector(1536), fts tsvector GENERATED,
     content_hash text UNIQUE, source_url, created_at
   - HNSW index on embedding (m=16, ef_construction=64, cosine)
   - GIN index on fts
   - search_rcw_wac(query_embedding, filter_corpus, match_count, min_similarity, query_text) RPC
     — hybrid: vector lane + BM25 lane, combined via RRF(k=60)
   - rcw_wac_stats() — returns single row: rcw_chunks, wac_chunks, usc_chunks, cfr_chunks,
     total_chunks, rcw_titles, wac_titles, total_queries, zero_hit_queries
   - rcw_wac_catalog(filter_corpus text) — returns title_num, title_name, chapter_num,
     chapter_name, chunk_count grouped by title/chapter
   - rcw_wac_query_log table: id, query_text, corpus_filter, result_count, created_at
   - GRANT EXECUTE on all RPCs to anon

5. ingestion/ingest.py
   - CLI: --corpus rcw|wac|usc|cfr, --titles (comma-sep), --dry-run, --clear
   - RCW/WAC: fetch XML from leg.wa.gov, parse sections, chunk at section boundary
   - USC: scrape Cornell LII per section (explicit section list per chapter, not range())
   - CFR: fetch full-title XML from eCFR.gov API with govinfo.gov fallback
   - Embed via OpenAI in batches, upsert to Supabase with content_hash deduplication
   - INSERT_BATCH=10 to avoid HNSW index timeout on large tables
   - Progress counters per corpus; completion summary shows all 4 corpora

6. .htaccess
   - Disable gzip/compression for SSE (LiteSpeed specific): 
     SetEnv no-gzip 1, SetEnv dont-vary 1

SYSTEM PROMPT STRUCTURE:
Describe the 4 corpora and legal hierarchy (federal > state). End with:
"The following law sections were retrieved as relevant to the question:\n\n{CONTEXT}"
where {CONTEXT} is replaced at runtime with retrieved sections formatted as:
"[RCW 28A.155.020 — Section Heading]\nfull content text\n\n"

KEY IMPLEMENTATION NOTES:
- SSE: PHP must set Content-Encoding: identity, disable all output buffering, send
  X-Accel-Buffering: no and X-LiteSpeed-Cache-Control headers
- Supabase anon key: use legacy eyJ... JWT, not sb_publishable_ (origin restrictions
  break server-side curl requests)
- Role timeouts in Supabase SQL Editor (required after setup):
  ALTER ROLE anon SET statement_timeout = '30000';
  ALTER ROLE authenticator SET statement_timeout = '30000';
  ALTER ROLE service_role SET statement_timeout = '0';
- Deduplication: SHA-256(section_id + content) in content_hash column, INSERT ... ON CONFLICT DO NOTHING
- Chat history: send last 12 messages to Claude; inject context only via system prompt, not user messages

Build all 6 files. PHP and JS share no secrets — all external API calls go through api-proxy.php.
```

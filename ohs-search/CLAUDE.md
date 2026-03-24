# OHS Search — Teacher-Facing Web Interface

## What This Is

OHS Search is the web interface for Orion High School's organizational memory system. It is a search page hosted at psd1.net/ohs-search that any teacher can use to ask questions about school policies, decisions, lesson plans, and institutional history — and get answers drawn from our actual archived documents.

It is the "getting answers" half of the OHS Memory project. The "putting knowledge in" half lives in the ohs-memory folder (the Python ingestion scripts).

## What Teachers Use It For

Any question about how we do things at Orion High School:
- "What is our late work policy?"
- "How do we handle phones in class?"
- "What economics assignments were used in 2025-26?"
- "What did we decide about advisory grades?"
- "What units does Physics cover in semester 1?"
- "What is this system?" (a fair question from a new hire)

## How It Works

1. Teacher types a question into the search box at psd1.net/ohs-search
2. The browser sends the query to `search-proxy.php`
3. The PHP script calls the OpenAI API to embed the query into a 1536-dimensional vector
4. The PHP script sends that vector to Supabase (our PostgreSQL database) via a REST API call to the `search_ohs_memory()` function
5. Supabase finds the most semantically similar document chunks using HNSW vector search
6. Results return to the browser as JSON and render as cards showing source, match percentage, and content
7. Teachers can filter by subject, school year, document type, and chunk size

The whole round trip takes about 1-2 seconds.

## Files

### `index.html`
The teacher-facing search UI. Single-page, no framework, no build step. Features:
- Search box with real-time filtering (subject, year, document type, chunk size)
- Results as cards with similarity percentage badges, source citations, and expandable content
- Document archive browser (second tab) showing all ingested documents
- Works on desktop and mobile

### `search-proxy.php`
Server-side proxy that handles the two API calls:
1. POST to `https://api.openai.com/v1/embeddings` — converts query text to a 1536-dim vector
2. POST to `https://qawqovyqnvlcyuxezmrp.supabase.co/rest/v1/rpc/search_ohs_memory` — vector similarity search

Accepts POST with JSON body: `{ query, subject, year, doc_type, chunk_size, limit }`
Returns JSON: `{ count, results[] }` where each result has content, source, similarity score, and metadata.

### `list-proxy.php`
Returns all ingested documents for the archive browser tab.
Calls `list_ohs_documents()` Supabase RPC function.
Accepts optional GET params: `subject`, `year`, `doc_type`.

### `config.php`
Contains API keys. This file must be filled in with real values before the page works. It is NOT committed to git. Contents:
- `OPENAI_API_KEY` — for embedding queries
- `SUPABASE_URL` — https://qawqovyqnvlcyuxezmrp.supabase.co
- `SUPABASE_ANON_KEY` — Supabase publishable key (read-only)

## Deployment

Hosted on psd1.net via cPanel. To deploy:
1. Fill in `config.php` with real API keys
2. Upload all four files (`index.html`, `search-proxy.php`, `list-proxy.php`, `config.php`) to the `ohs-search/` directory on the server via cPanel File Manager or FTP
3. Verify the page loads at `https://psd1.net/ohs-search/`

The page requires no database on the web server itself — all database queries go directly from PHP to Supabase over HTTPS.

## The Chunk Size Filter

The search interface exposes a "chunk size" filter that most users will never touch:
- **Best match (auto)** — searches all chunks, returns highest similarity regardless of size
- **Precise (small chunks)** — ~200-token chunks, better for specific factual questions
- **Contextual (large chunks)** — ~900-token chunks, better for questions needing background

This filter exists because the OHS Memory ingestion system stores every document at two chunk sizes simultaneously. This was a deliberate design decision to allow comparison and tuning.

## Relationship to Other Systems

OHS Search is the web interface to the same database used by:
- `ohs-memory/mcp_server.py` — the MCP server that lets Claude Code and Claude Desktop query the same knowledge base
- `ohs-memory/search.py` — the command-line search tool used for testing and chunk comparison

All three share the same Supabase database and the same OpenAI embedding model (text-embedding-3-small, 1536 dimensions).

## What Is Not Here

OHS Search is read-only. It cannot add documents to the database. Document ingestion is done by administrators running `ohs-memory/ingest.py` locally. This is intentional — a human reviews every document before it enters the knowledge base.

## The Vision

Every new teacher hired at Orion High School should be able to come to this page on their first day and learn how we do things — from the actual decisions we made, in the actual words we used, sourced from the actual documents where those decisions were recorded. Not from memory, not from a single person's interpretation, but from the archive.

The system will be as good as the documents we put into it. The technology is not the constraint. The discipline of archiving our decisions is the constraint.

---

## Technical Reference (for Claude Code)

> This section exists so Claude Code can skip the codebase exploration phase.
> Point here and say "fix BUG-001" or "build FEATURE-001."

### Full File Map

**ohs-search/ (this folder — PHP/HTML, deployed to psd1.net/ohs-search/)**

| File | Role |
|------|------|
| `index.html` | Single-page UI. Tabs: "Ask the Log" (synthesized answers) + "Flight Records" (archive browser). |
| `search-proxy.php` | Entry point for search POST. Calls SearchProxy, handles timeout retry, logs query. Default `limit=8`. |
| `src/SearchProxy.php` | Core logic: `getEmbedding()` → OpenAI, `searchSupabase()` → Supabase RPC, `synthesizeAnswer()` → Claude Haiku (claude-haiku-4-5, max_tokens=600, top 6 results), `logQuery()` → fire-and-forget. |
| `list-proxy.php` | Returns document list for archive tab via `list_ohs_documents()` RPC. |
| `ingest-email-proxy.php` | HTTP endpoint for Outlook VBA macro. Receives email headers + body, chunks (~150 words small / ~675 words large), embeds, inserts to Supabase. |
| `dashboard.php` | Admin stats: corpus counts, storage %, ingestion trends, low-hit queries. Calls `flightlog_stats()` RPC. |
| `ohskey.php` | **Not in git.** At `../.secrets/ohskey.php` on server. Keys: `OPENAI_API_KEY`, `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `ANTHROPIC_API_KEY`. |

**ohs-memory/ (sibling folder — Python, runs locally)**

| File | Role |
|------|------|
| `ingest.py` | CLI ingestion. Handles `.pdf` (Claude API, falls back to pdfplumber), `.docx` (python-docx), `.pptx`, `.xlsx`, `.md`, `.txt`, `.msg` (extract-msg). SHA-256 dedup. Two chunk sizes per doc. |
| `split_and_ingest_meetings.py` | Splits the OneNote meetings export (pandoc → .md) into one .txt per meeting date and ingests each. Run after updating the master Meetings .docx. See BUG-002. |
| `schema.sql` | Source-of-truth DB schema. `documents`, `chunks`, `query_log` tables + HNSW index + all RPC functions. Re-run in Supabase SQL Editor to apply changes. |
| `search.py` | CLI search tool. Direct psycopg2. Good for debugging retrieval without the web layer. |
| `mcp_server.py` | MCP server for Claude Desktop / Claude Code. Tools: `search_decisions()`, `list_documents()`, `get_corpus_stats()`. |
| `FlightLogAnEmail.bas` | Outlook macro: select email → POST to `ingest-email-proxy.php`. |
| `export_outlook_folder.vba` | Outlook macro: bulk-export selected Outlook folder to `.msg` files. |

### Data Flow

```
INGEST (Python, local)
  File → extract_text() → SHA-256 dedup → chunk at 2 sizes (small ~200tok / large ~900tok)
       → embed via OpenAI → INSERT documents + chunks → Supabase

SEARCH (PHP, server)
  Browser POST {query, synthesize:true, limit:8}
  → search-proxy.php → OpenAI embed query
  → Supabase RPC search_ohs_memory() → top-N chunks by cosine similarity (no threshold)
  → Claude Haiku synthesizeAnswer() → prose with [Source N] citations
  → JSON {answer, count, results[]} → browser
  → logQuery() → query_log table
```

### Schema Changes Applied (March 2026)

**Hybrid search (BM25 + pgvector, Reciprocal Rank Fusion):**
- `chunks` table gained a `fts tsvector GENERATED ALWAYS AS (to_tsvector('english', content)) STORED` column
- GIN index `chunks_fts_gin` added for fast keyword search
- `search_ohs_memory()` updated to run two parallel lanes (vector cosine + BM25 keyword) and fuse
  results using RRF (`1/(k+rank)` where k=60). Chunks appearing in both lanes rank highest.
- BM25 uses OR semantics: the query is stemmed via `to_tsvector()` and lexemes joined with `|` so
  "When did John Weisenfeld join the team?" matches chunks with ANY of: join, john, weisenfeld, team.
  This prevents AND-requiring keyword search from missing chunks that don't contain every query word.
- `min_similarity` applies only to the vector lane. BM25 hits with no vector match appear at 0% in the UI.

**Applying DDL on Supabase when SQL editor times out:**
- `SET statement_timeout = '120000';` at the top of the editor tab (120 seconds)
- Or use Python psycopg2 directly (no timeout): `python -c` won't work on Windows CMD for multi-line;
  write a `.py` file instead. `psql` is not installed on this machine.

---

### Database Schema (key points)

**`documents`** — one row per source file
- `source_hash` VARCHAR unique — SHA-256 dedup key
- `doc_type`: `email` | `lesson_plan` | `meeting_notes` | `policy` | `other`
- `school_year`: `pre-opening` | `2024-25` | `2025-26`
- `raw_text` — full extracted text stored for future re-embedding

**`chunks`** — many per document
- `chunk_size`: `'small'` (~200 tok) | `'large'` (~900 tok)
- `embedding vector(1536)` — OpenAI text-embedding-3-small
- `fts tsvector` — GENERATED ALWAYS AS (to_tsvector('english', content)) STORED
- HNSW index: `chunks_embedding_hnsw`, m=16, ef_construction=64
- GIN index: `chunks_fts_gin` on `fts` column (BM25 keyword search)

**`query_log`** — every search query logged: `query_text`, `result_count`, `had_answer`, `queried_at`

**Key RPC functions** (edit in `schema.sql`, apply in Supabase SQL Editor):
- `search_ohs_memory(query_embedding, match_count, filter_subject, filter_year, filter_doc_type, filter_chunk_size)` — the heart of search; returns ranked chunks + document metadata
- `list_ohs_documents(...)` — archive browser
- `flightlog_stats()` — all dashboard data in one round trip; `security definer` so anon key can read
- `insert_email_document()` — dedup-aware insert called by `ingest-email-proxy.php`

---

## Known Bugs

### BUG-001 — Search Always Returns Exactly 8 Hits ✅ FIXED
**Status:** Fixed and deployed to Supabase. `search_ohs_memory()` now has `min_similarity float default 0.40`.
Nonsense queries ("syzygy kumquat") return 0 results. PHP files need deploying to psd1.net if not done yet.

**What was done:**
- Added `min_similarity float default 0.40` parameter to `search_ohs_memory()` in `schema.sql`
- `SearchProxy::searchSupabase()` gained `float $minSimilarity = 0.40` parameter, passed in `$params`
- `search-proxy.php` reads optional `min_similarity` from request body, defaults to 0.40
- `index.html` answer footer shows "No strong matches found — answer may be speculative" when count=0
- Threshold tuning history: 0.25 (too low, everything passes) → 0.35 (still too low) → 0.40 (cuts noise)
- **Noise floor for this corpus is ~34-37%** (verified with "syzygy kumquat" test)

**Threshold note:** BUG-003 fixed email signature noise; threshold lowered to 0.30 as planned.

---

### BUG-002 — Meeting Notes Chunk Diluted by Multi-Meeting DOCX ✅ FIXED
**Symptom:** Query "When did Weisenfeld join the team" returns only emails, never the meeting notes
containing "Welcome John Weisenfeld to the team!" (3/10/25).

**Full diagnosis:**
- `Meetings (FlightLog 2026-03-22).docx` IS in the corpus; text extraction works correctly
- The relevant chunk IS in Supabase — contains "Welcome John Weisenfeld to the team!"
- **But it scores only 34.9%** because the OneNote export concatenates all 45 meetings into one file.
  When chunked at ~900 tokens, one chunk spans multiple meeting dates, so the 3/10/25 content is
  diluted by surrounding unrelated meeting content. The embedding averages across all of it.
- Additionally, this chunk was being outranked by emails even after BUG-003 (pure cosine similarity
  favors the email corpus by volume).

**What was done:**
1. Deleted the monolithic `Meetings (FlightLog 2026-03-22).docx` from the DB (and its two duplicate
   `Meetings.docx` entries that were ingested twice in quick succession — same content, same chunk count)
2. Added hybrid search (BM25 + pgvector RRF) — see Schema Changes below
3. Created `ohs-memory/split_and_ingest_meetings.py` — splits the OneNote export into one `.txt`
   per meeting date, then ingests each with clean isolated embeddings
4. Run: `python ohs-memory/split_and_ingest_meetings.py`
   - Reads `meetings_extracted.md` (pandoc output of the .docx)
   - Writes ~45 individual files to `split_meetings/pre-opening/` and `split_meetings/2025-26/`
   - Ingests each group with `--subject All-Staff --type meeting_notes --year {group}`

**Source file location:** `C:\Users\johnw\Documents\orion-planning-team-onenote\Meetings (FlightLog 2026-03-22).docx`
**Pandoc extraction:** `pandoc "Meetings (FlightLog 2026-03-22).docx" -o meetings_extracted.md`

---

### BUG-003 — Email Signature Noise Polluting Search Results ✅ FIXED
**Symptom:** Queries for people's names (e.g. "Weisenfeld") return chunks that are just his
Microsoft SafeLinks-wrapped email signature — not actual email content. Content looks like:
```
?Weisenfeld (he/him) <https://nam11.safelinks.protection.outlook.com/?url=http%3A%2F%2Fwww.linkedin.com%2Fin%2Fweisenfeldj&data=05%7C02%7C...
```
These score 51-59% on name queries and outrank legitimate content.

Also affects: some emails contain base64-encoded MIME parts or SharePoint `xsdata=` tokens that
score 34-37% on every query (general noise floor pollution).

**Root cause:** `_extract_text_from_msg()` in `ohs-memory/ingest.py` extracts the raw email body
verbatim, including:
1. **SafeLinks-wrapped email signatures** — Outlook replaces hyperlinks with
   `https://nam11.safelinks.protection.outlook.com/?url=...` wrappers. Signature blocks
   (name, title, LinkedIn, phone) end up as dense URL-encoded chunks.
2. **Base64-encoded MIME parts** — forwarded/encoded email content stored verbatim.
3. **SharePoint sharing tokens** — `xsdata=MDV8MDJ8...&reserved=0>` appended to URLs.

**What was done:**
- Added `_sanitize_email_body()` to `ohs-memory/ingest.py`, called on body text before chunking
- Sanitizer strips: SafeLinks URLs, lines with >30% `%XX` density, `xsdata=`/`&reserved=0` tokens, base64-looking lines (60+ chars, no spaces), and `-- ` signature delimiter + everything after
- Lowered `min_similarity` default from 0.40 → 0.30 in `schema.sql`, `SearchProxy.php`, `search-proxy.php`

**Remaining manual steps (run once):**
1. Identify and delete affected documents from Supabase (chunks cascade).
   **Use `doc_type = 'email'` as a guard** — the base64 regex `[A-Za-z0-9+/]{60,}` is too broad
   and will also match long URLs or SHA hashes in non-email documents:
   ```sql
   -- Safe: scoped to emails only
   DELETE FROM documents
   WHERE doc_type = 'email'
     AND (
       raw_text ILIKE '%safelinks.protection.outlook.com%'
       OR raw_text ILIKE '%xsdata=%'
     );
   ```
2. Re-ingest the source `.msg` files with the cleaned extractor:
   ```bash
   python ohs-memory/ingest.py ./msg-files/ --subject All-Staff --year 2024-25
   ```
3. Re-run Supabase SQL function update (paste updated `search_ohs_memory` from `schema.sql`)
4. Deploy updated PHP files to psd1.net
5. Verify "When did Weisenfeld join the team" now surfaces the 3/10/25 meeting notes chunk

---

## Per-Document-Type Ingestion Notes

Each document type has quirks that require custom pre-processing before standard ingest works well.
This is not a bug — it is the nature of unstructured organizational data.

| Type | Problem | Solution |
|------|---------|----------|
| **Emails (.msg)** | Microsoft SafeLinks wraps every URL in a redirect; signature blocks (name, title, LinkedIn, phone) become dense URL-encoded chunks that score 50%+ on any name query. Base64-encoded MIME parts add general noise. | `_sanitize_email_body()` in `ingest.py` strips SafeLinks, `%XX`-heavy lines, `xsdata=` tokens, base64 lines, and `-- ` signature delimiters. |
| **Meeting notes (.docx from OneNote export)** | OneNote exports all meetings into one file. When chunked at 900 tokens, each chunk spans multiple meeting dates. Embeddings are diluted averages across unrelated content. | Use `split_and_ingest_meetings.py` to split into one .txt per meeting date before ingesting. |
| **PDFs** | Generally fine. Claude API extraction handles scanned pages. | No special treatment needed unless handwritten. |
| **Policy docs (.docx)** | Generally fine if one topic per file. | No special treatment needed. |
| **Presentations (.pptx)** | Slide text can be sparse; speaker notes contain more content. | Ingest as-is; consider extracting notes separately if retrieval is poor. |

**Guiding principle for ingestion problems:**
1. Confirm the chunk exists in the DB first (SQL `ILIKE` search on `content`)
2. Check its actual similarity score for the failing query (use `search_ohs_memory` RPC with `match_count=30, min_similarity=0.0`)
3. If chunk exists but scores low → embedding is diluted → fix the source document structure (split, clean, re-ingest)
4. If chunk doesn't rank in top-N despite adequate score → increase limit or check for volume imbalance (1200 emails vs 4 meeting docs)
5. Only add search complexity (hybrid, reranking) after confirming the simple fixes are insufficient

---

## Feature Backlog

### FEATURE-001 — Thumbs-Down Feedback / Quality Incident Reporting
**Request:** When a synthesized answer is wrong, user clicks 👎. Modal captures optional reporter name + comment. Stored persistently. Dashboard shows "Quality Reports" section.

**Files to change:**

1. **`schema.sql`** — Add `feedback` table + `insert_feedback` RPC + update `flightlog_stats()`:
```sql
create table if not exists feedback (
  id           bigint      primary key generated always as identity,
  query_text   text        not null,
  answer_text  text,
  result_count int,
  reporter     text,
  comment      text,
  reported_at  timestamptz default now()
);
```

2. **`index.html`** — Add 👎 button in `.answer-footer` next to toggle-sources-btn. On click: show inline modal (name field optional, comment textarea optional, Submit + Cancel). On submit: `POST feedback-proxy.php {query, answer, result_count, reporter, comment}`. Show confirmation inline ("Reported — thanks").

3. **New `feedback-proxy.php`** — Thin wrapper: read secrets → call Supabase `insert_feedback()` RPC → return `{ok:true, id:N}`.

4. **`dashboard.php`** — New "Quality Reports" section: stat card for total thumbs-down count, table showing recent reports (Query | Comment | Reporter | Date | re-run link).

---

## CLI Quick Reference

```bash
# Search without the web layer (good for debugging)
python ohs-memory/search.py "your query here"
python ohs-memory/search.py "your query" --compare        # small vs large chunks side-by-side
python ohs-memory/search.py "your query" --subject Physics
python ohs-memory/search.py --list                        # all ingested documents

# Ingest
python ohs-memory/ingest.py path/to/file.docx --subject All-Staff --year 2024-25 --type meeting_notes
python ohs-memory/ingest.py ./folder/ --subject All-Staff --year 2024-25  # batch
python ohs-memory/ingest.py path/to/file.docx --update   # re-ingest changed file
```

## Deployment

- PHP files: deploy to cPanel at psd1.net via FTP/cPanel File Manager
- Secrets: `/home/{user}/.secrets/ohskey.php` (above document root)
- SQL changes: paste updated sections from `schema.sql` into Supabase SQL Editor → Run
- Python scripts: run locally on John's machine only

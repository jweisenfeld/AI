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

### Database Schema (key points)

**`documents`** — one row per source file
- `source_hash` VARCHAR unique — SHA-256 dedup key
- `doc_type`: `email` | `lesson_plan` | `meeting_notes` | `policy` | `other`
- `school_year`: `pre-opening` | `2024-25` | `2025-26`
- `raw_text` — full extracted text stored for future re-embedding

**`chunks`** — many per document
- `chunk_size`: `'small'` (~200 tok) | `'large'` (~900 tok)
- `embedding vector(1536)` — OpenAI text-embedding-3-small
- HNSW index: `chunks_embedding_hnsw`, m=16, ef_construction=64

**`query_log`** — every search query logged: `query_text`, `result_count`, `had_answer`, `queried_at`

**Key RPC functions** (edit in `schema.sql`, apply in Supabase SQL Editor):
- `search_ohs_memory(query_embedding, match_count, filter_subject, filter_year, filter_doc_type, filter_chunk_size)` — the heart of search; returns ranked chunks + document metadata
- `list_ohs_documents(...)` — archive browser
- `flightlog_stats()` — all dashboard data in one round trip; `security definer` so anon key can read
- `insert_email_document()` — dedup-aware insert called by `ingest-email-proxy.php`

---

## Known Bugs

### BUG-001 — Search Always Returns Exactly 8 Hits
**Symptom:** Answer card always says "Drawn from 8 source records." Result count in `query_log` is always 8.

**Root cause:** `index.html` hardcodes `limit: 8` in `doSearch()`. `search-proxy.php` passes this as `match_count` to the SQL RPC. The SQL function has no similarity threshold — it returns exactly `match_count` rows regardless of relevance. With thousands of chunks in the corpus, all 8 slots are always filled.

**Fix:**
Option A (SQL) — Add `min_similarity float default 0.35` parameter to `search_ohs_memory()` in `schema.sql`:
```sql
and (1 - (c.embedding <=> query_embedding)) >= min_similarity
```
Option B (PHP) — Filter in `SearchProxy::searchSupabase()` after retrieval:
```php
return array_values(array_filter($results, fn($r) => $r['similarity'] >= 0.35));
```
Option A is cleaner. Apply by updating `schema.sql` and running in Supabase SQL Editor.
**Note:** 0.25 was tried and was too permissive — domain-specific corpora share vocabulary so scores cluster high. 0.35 is the current deployed default. Tune up toward 0.40 if weak answers persist; tune down toward 0.30 if valid results go missing.

Side benefit: `query_log.result_count` becomes meaningful — queries with no good matches will return 0-3 instead of always 8, making the dashboard "Knowledge Gaps" report accurate.

---

### BUG-002 — Meeting Notes Not Surfaced; Only Emails Return for People/Event Queries
**Symptom:** Query "When did Weisenfeld join the Team" returns only `.msg` email chunks. Meeting notes from OneNote (containing "Welcome John Weisenfeld to the team!" dated 3/10/25) are not in the results.

**Root causes (in order of probability):**

1. **Doc may not be in corpus.** If the OneNote export was a `.xml` file (Word XML format), `ingest.py`'s `extract_text()` only matches `.docx` — the file would be silently skipped. Check: go to Flight Records tab and search for the document by name.

2. **OneNote → Word XML text extraction failure.** `_extract_text_from_docx()` (ingest.py lines 267-299) iterates `doc.paragraphs` and `doc.tables`. OneNote exports sometimes put content in **floating text boxes / shapes** — a known python-docx blind spot. Result: extracted text is near-empty → weak embedding → low similarity score.

3. **Email volume dominance.** No deduplication across source documents — multiple chunks from the same email fill result slots. With far more email chunks than meeting-note chunks, emails flood the top-8 even when a meeting note is the right answer. BUG-001's fix (similarity threshold) also helps here.

**Diagnostic steps:**
```bash
# Check if doc is in corpus
python ohs-memory/search.py --list | grep -i "weisenfeld\|3-10\|minutes\|3.10"

# Check raw similarity scores
python ohs-memory/search.py "When did Weisenfeld join the team" --compare

# If doc exists but text is empty, check in Supabase SQL Editor:
# SELECT original_filename, length(raw_text), LEFT(raw_text,200)
# FROM documents WHERE original_filename ILIKE '%minutes%' OR original_filename ILIKE '%3-10%';
```

**Fix:**
- If doc missing/empty: re-ingest as PDF (print to PDF from OneNote — Claude API extraction handles it perfectly)
- Long-term: add Claude API fallback in `_extract_text_from_docx()` when `len(extracted_text) < 200`

---

### BUG-003 — Emails with Encoded/Binary Content Producing Junk Embeddings
**Symptom:** Source cards show garbled content like `qKOnLi4H9WYlC2cD-2Bh578Qh0HR...` or
`xsdata=MDV8MDJ8b3Jpb25A...` — these are base64-encoded email bodies or SharePoint URL tokens,
not readable text. They score ~34-37% similarity on *every* query (noise floor), polluting results.

**Root cause:** Some emails in the corpus contain MIME-encoded parts or forwarded messages with
SharePoint sharing link tokens that were extracted verbatim by `extract-msg`. The ingest pipeline
stored them as-is, so their embeddings reflect encoded noise rather than meaning.

**Diagnostic:** In Supabase SQL Editor:
```sql
SELECT id, original_filename, LEFT(raw_text, 200)
FROM documents
WHERE raw_text ILIKE '%xsdata=%'
   OR raw_text ILIKE '%MDV8MDJ8%'
   OR raw_text ~ '^[A-Za-z0-9+/]{40,}={0,2}$'
ORDER BY ingested_at DESC;
```

**Fix options:**
- Delete the offending document records (chunks cascade-delete automatically):
  `DELETE FROM documents WHERE id IN (...);` then re-ingest the source `.msg` files
  after stripping encoded content in `_extract_text_from_msg()` in `ingest.py`
- Add a content sanitizer to `_extract_text_from_msg()` that strips lines matching
  base64 patterns or SharePoint `xsdata=` tokens before chunking

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

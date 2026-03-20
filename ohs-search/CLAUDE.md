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

# rcw-wac/ — CLAUDE.md

## What This Is

A RAG (Retrieval-Augmented Generation) chat interface for Washington State and federal law. Users ask plain-language questions and receive answers grounded in specific law sections, with clickable citations to official sources.

- **RCW** = Revised Code of Washington — statutes enacted by the Legislature
- **WAC** = Washington Administrative Code — agency rules written under legislative authority
- **USC** = United States Code — federal statutes enacted by Congress
- **CFR** = Code of Federal Regulations — federal agency rules

## How It Works

```
User query
  │
  ▼
api-proxy.php
  ├─ 1. Embed query via OpenAI text-embedding-3-small (1536 dim)
  ├─ 2. Search Supabase: search_rcw_wac() RPC (vector + BM25 hybrid, top 8)
  ├─ 3. Emit SSE event: {"sources": [...]}   ← UI renders source cards immediately
  ├─ 4. Build system prompt: SYSTEM_PROMPT + retrieved sections as {CONTEXT}
  └─ 5. Stream Claude Haiku → SSE {"text":"..."} deltas → browser
```

Round-trip time: ~1s for embed+search, then streaming starts immediately.

## Files

| File | Role |
|------|------|
| `index.html` | Chat UI. WA blue theme. Corpus toggle (All/State/RCW/WAC/Federal/USC/CFR). Source cards with citation links. About modal (pipeline, live DB stats, system prompt inspector, corpus catalog). |
| `api-proxy.php` | Entry point. Embed → search → emit sources → stream Claude. Also serves ?stats=1, ?prompt=1, ?catalog= routes for the About modal. |
| `src/RcwWacProxy.php` | Core class: `getEmbedding()`, `searchSupabase()`, `buildContext()`, `logQuery()`. Shared by rcw-wac-leo/. |
| `ingestion/schema.sql` | Supabase schema: `rcw_wac_chunks`, `rcw_wac_query_log`, `search_rcw_wac()` RPC, `rcw_wac_stats()` RPC, `rcw_wac_catalog()` RPC. |
| `ingestion/ingest.py` | CLI ingestion: parse XML → chunk → embed → upsert. Retry/backoff on insert. 0.5s IO throttle between batches. |
| `ingestion/requirements.txt` | Python dependencies. |
| `howto.md` | Architecture docs + full Claude regeneration prompt. |

## Database

Dedicated Supabase project: `ogcmyupxiykyngzeftwy.supabase.co`
(separate from OHS Memory — different audience, different security posture)
Tables: `rcw_wac_chunks`, `rcw_wac_query_log`.

**`rcw_wac_chunks`** — one row per text chunk:
- `corpus`: `'rcw'` | `'wac'` | `'usc'` | `'cfr'`
- `section_id`: `'RCW 28A.400.010'` | `'WAC 392-121-122'` | `'20 USC 1415'` | `'34 CFR 300.8'`
- `embedding vector(1536)`: OpenAI text-embedding-3-small
- `fts tsvector`: GENERATED from section_id + heading + content (BM25 lane)
- HNSW index on embedding (m=16, ef_construction=64), GIN index on fts

**`search_rcw_wac()` RPC**: hybrid vector + BM25 with Reciprocal Rank Fusion (k=60).
Filter params: `filter_corpus`, `filter_title`, `min_similarity`.

**`rcw_wac_stats()` RPC**: returns a single flat row:
`{rcw_chunks, wac_chunks, usc_chunks, cfr_chunks, total_chunks, rcw_titles, wac_titles, total_queries, zero_hit_queries}`

**`rcw_wac_catalog(filter_corpus text)` RPC**: returns `TABLE(title_num, title_name, chapter_num, chapter_name, chunk_count)`.
Used by the About modal's corpus catalog browser.

## Secrets

Uses `../.secrets/rcwkey.php` (its own file, NOT ohskey.php). Keys needed:
- `ANTHROPIC_API_KEY`
- `OPENAI_API_KEY`
- `SUPABASE_URL`       — https://ogcmyupxiykyngzeftwy.supabase.co
- `SUPABASE_ANON_KEY`  — legacy `eyJ...` JWT anon key (NOT the new sb_publishable_ key)

## Deployment

1. Run `ingestion/schema.sql` in Supabase SQL Editor
2. Ingest law XML files (see Ingestion section below)
3. Upload `index.html`, `api-proxy.php`, `src/`, `.htaccess` to `psd1.net/rcw-wac/` via cPanel
4. Verify at `https://psd1.net/rcw-wac/`

## Supabase Project Setup

This project uses its own dedicated Supabase project — separate from OHS Memory.
Reasons: different audience (public vs. staff-only), different security posture,
designed for potential handoff to the State of WA or other municipalities.

### Two keys, two purposes

| Key | Where used | Why |
|-----|-----------|-----|
| `anon` (publishable) | `api-proxy.php` on the web server | Read-only; calls RPCs + inserts query logs |
| `service_role` (secret) | `ingest.py` on your local machine | Bypasses RLS to INSERT chunks; never on server |

Find both in: Supabase → Project Settings → API

### Role timeout configuration (REQUIRED — run once in SQL Editor)

```sql
-- Prevents HNSW index-update timeouts during web queries
ALTER ROLE anon          SET statement_timeout = '30000';
ALTER ROLE authenticator SET statement_timeout = '30000';

-- Prevents ingest timeout (HNSW index update time grows with table size)
ALTER ROLE service_role  SET statement_timeout = '0';

-- Apply immediately (no restart needed)
NOTIFY pgrst, 'reload config';
```

Default Supabase statement_timeout is 8s — too short for HNSW updates on large tables.

### Compute

Use **Micro Compute** (1 GB RAM, dedicated CPU). It's included with the Pro plan but
must be manually claimed: Project Settings → Infrastructure → Compute → "Upgrade to Micro".
Without it, HNSW index fits partially in cache → slower queries, random 502 errors during ingest.

### New project checklist

1. Create account/project at supabase.com (free tier — separate from OHS Memory)
2. Enable the `vector` extension: Database → Extensions → search "vector" → Enable
3. Run `ingestion/schema.sql` in the SQL Editor
4. Apply role timeout configuration (see above)
5. Upgrade to Micro Compute
6. Copy `anon` key → into `rcwkey.php` in your `.secrets/` folder on the server
7. Copy `service_role` key → into a local `.env` file for running `ingest.py`
8. Never commit either key to git

### Secrets file for PHP (`~/.secrets/rcwkey.php` on the server)

```php
<?php
return [
    'ANTHROPIC_API_KEY' => 'sk-ant-...',
    'OPENAI_API_KEY'    => 'sk-...',
    'SUPABASE_URL'      => 'https://yourproject.supabase.co',
    'SUPABASE_ANON_KEY' => 'eyJ...',   // anon key only — not service_role
];
```

**Supabase anon key format**: Use the legacy `eyJ...` JWT key, not the newer `sb_publishable_...`
format. The new format has origin restrictions that block server-side PHP curl requests.

## Architecture Clarification — No Live Web Queries

**The frontend never queries leg.wa.gov.** Ingestion crawls it ONCE to populate Supabase.
After that, all user queries go to Supabase only. The leg.wa.gov links in source cards are
just citation links that open in a new tab.

```
INGESTION (one-time, you run manually):
  ingest.py → crawls leg.wa.gov title-by-title → embeds → stores in Supabase

USER QUERIES (every question):
  Browser → api-proxy.php → OpenAI (embed) → Supabase (search) → Claude
  leg.wa.gov is never touched at query time.
```

## Ingestion

There is no single downloadable file for the full RCW or WAC. The legislature publishes
it by title/chapter/section on their website. The ingestion script crawls those pages.

### Phase 1 (recommended starting point)

```bash
cd rcw-wac/ingestion/
pip install -r requirements.txt

export OPENAI_API_KEY=sk-...
export SUPABASE_URL=https://ogcmyupxiykyngzeftwy.supabase.co
export SUPABASE_SERVICE_ROLE_KEY=eyJ...

# ALWAYS dry-run first — verify the HTML parser is extracting real text
python ingest.py --corpus rcw --titles 28A --dry-run
python ingest.py --corpus wac --titles 180 --dry-run

# If dry-run output looks good, run for real
python ingest.py --corpus rcw --titles 28A        # K-12 education law
python ingest.py --corpus wac --titles 180        # OSPI administrative rules
python ingest.py --corpus rcw --titles 42.56      # Public records

# Re-ingest after law updates
python ingest.py --corpus rcw --titles 28A --clear
```

### Ingest limits — disk IO budget

Free-tier Nano has a daily IO budget (30 min burst at 2085 Mbps, then 43 Mbps baseline).
With Micro Compute the baseline is 87 Mbps. Rules of thumb:
- **Max concurrent ingest windows: 5–6** (10+ triggers Cloudflare 502 errors)
- ingest.py sleeps 0.5s between INSERT batches to throttle IO
- INSERT_BATCH=10, flush threshold=200 rows
- ingest.py has retry with exponential backoff (up to 5 attempts: 1s, 2s, 4s, 8s, 16s)

After completing a large ingest run, optionally rebuild the HNSW index more efficiently:
```sql
REINDEX INDEX CONCURRENTLY rcw_wac_chunks_embedding_idx;
```

### Estimated corpus size (Phase 1)
| Title | ~Sections | ~Chunks | Crawl time | Embed cost |
|-------|-----------|---------|------------|------------|
| RCW 28A | ~850 | ~1,000 | ~7 min | ~$0.04 |
| WAC 180 | ~600 | ~700 | ~5 min | ~$0.03 |
| RCW 42.56 | ~80 | ~100 | ~1 min | <$0.01 |
| **Total** | **~1,530** | **~1,800** | **~13 min** | **~$0.07** |

Full corpus (all ~96 RCW titles + all WAC + USC + CFR): ~25,000+ chunks, ~2-4 hours crawl, ~$1.00 embed.

## Model

`api-proxy.php` uses **Claude Haiku 4.5** (`claude-haiku-4-5-20251001`) for generation.
`MAX_OUTPUT_TOKENS = 2000` (raised from 1200 to handle complex multi-section queries).
- Fast (~1s TTFB for a 2k-token context)
- Cheap (~$0.003/query at typical context sizes)
- Adequate for synthesis of retrieved legal text

When Claude hits `max_tokens`, the UI shows "⚠ response clipped" in the meta badge and
a "Continue response →" button that re-sends with a continuation prompt.

## API Routes

| Route | Description |
|-------|-------------|
| `POST ?stream=1` | Main query: embed → search → stream Claude response |
| `GET ?stats=1` | Live DB chunk/title counts (proxies `rcw_wac_stats()` RPC) |
| `GET ?prompt=1` | Returns current system prompt as JSON |
| `GET ?catalog=rcw\|wac\|usc\|cfr` | Returns ingested titles/chapters (proxies `rcw_wac_catalog()`) |
| `GET ?stream=test` | Streaming sanity check (no API calls) |

## SSE Event Protocol

```
data: {"sources": [{section_id, section_heading, corpus, source_url, similarity_pct}, ...]}
data: {"text": "...streaming delta..."}
data: {"text": "..."}
...
data: {"meta": {inTokens, outTokens, cachedTokens, resultCount, stopReason}}
data: [DONE]
```

Sources are emitted **before** text starts so the UI can render citation cards while Claude types.
`stopReason` is `'end_turn'` (normal) or `'max_tokens'` (response clipped).

## About Modal

The About button in the header opens a modal with:
1. **Query pipeline diagram** — shows the 4-step RAG flow
2. **Live DB stats** — fetches `?stats=1`, shows per-corpus chunk/title counts; clicking a stat card opens the corpus catalog
3. **System prompt inspector** — fetches `?prompt=1`, shows the exact prompt with a Copy button
4. **Technical notes** — model, embedding, retrieval, corpus sources

Clicking a corpus badge on a source card (in chat) also opens the corpus catalog for that corpus.

## Server Notes

**LiteSpeed gzip compression** must be disabled for SSE streaming to work. The `.htaccess` file
handles this. Symptom when broken: garbled binary output or responses appear all at once.

## Multilingual Support

The OpenAI embedding model maps semantically equivalent text across languages to nearby vectors.
Spanish queries work automatically — Claude responds in the language of the query.
Example chips: "Mi hijo es autista; creo que necesita estar bajo un IEP o un Plan 504. ¿Cuáles son los pasos a seguir?"

## Known Limitations / Future Work

1. **XML structure varies** — WA Legislature XML schema is inconsistent across title downloads.
   If a title imports 0 sections, inspect the XML element names and adjust parser in `ingest.py`.
   Run `--dry-run` first and check the sample output.

2. **Cross-references not resolved** — "See RCW 42.56.010" in a retrieved section doesn't auto-fetch
   that section. Phase 2: scan `content` for `RCW/WAC \d+` patterns and expand retrieved set.

3. **No freshness tracking** — ingested content may go stale when Legislature amends law.
   Add `effective_date` column and a scheduled re-ingest (or manual trigger after session ends).

4. **Chat history context** — currently sends last 6 turns. For follow-up questions
   ("what about teachers specifically?"), the prior answer provides sufficient context.

## CLI Quick Reference

```bash
# Test parsing (no API calls, no DB writes)
python ingest.py --corpus rcw --titles 28A --dry-run

# Ingest Phase 1
python ingest.py --corpus rcw --titles 28A
python ingest.py --corpus wac --titles 180

# Replace after law update
python ingest.py --corpus rcw --titles 28A --clear

# Verify corpus in Supabase SQL Editor:
# SELECT corpus, title_num, count(*) FROM rcw_wac_chunks GROUP BY 1,2 ORDER BY 1,2;
# SELECT * FROM rcw_wac_stats();

# Rebuild HNSW index after large ingest (optional, improves query speed):
# REINDEX INDEX CONCURRENTLY rcw_wac_chunks_embedding_idx;
```

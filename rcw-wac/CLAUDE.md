# rcw-wac/ — CLAUDE.md

## What This Is

A RAG (Retrieval-Augmented Generation) chat interface for Washington State law. Users ask plain-language questions and receive answers grounded in specific RCW/WAC sections, with clickable citations to the official WA Legislature website.

- **RCW** = Revised Code of Washington — statutes enacted by the Legislature
- **WAC** = Washington Administrative Code — agency rules written under legislative authority

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
| `index.html` | Chat UI. WA blue theme. Corpus toggle (Both/RCW/WAC). Source cards with leg.wa.gov links. |
| `api-proxy.php` | Entry point. Embed → search → emit sources → stream Claude. |
| `src/RcwWacProxy.php` | Core class: `getEmbedding()`, `searchSupabase()`, `buildContext()`, `logQuery()`. |
| `ingestion/schema.sql` | Supabase schema: `rcw_wac_chunks`, `rcw_wac_query_log`, `search_rcw_wac()` RPC. |
| `ingestion/ingest.py` | CLI ingestion: parse XML → chunk → embed → insert. |
| `ingestion/requirements.txt` | Python dependencies. |

## Database

Same Supabase project as OHS Memory (`qawqovyqnvlcyuxezmrp.supabase.co`).
New tables: `rcw_wac_chunks`, `rcw_wac_query_log`.

**`rcw_wac_chunks`** — one row per text chunk:
- `corpus`: `'rcw'` | `'wac'`
- `section_id`: `'RCW 28A.400.010'` | `'WAC 392-121-122'`
- `embedding vector(1536)`: OpenAI text-embedding-3-small
- `fts tsvector`: GENERATED from section_id + heading + content (BM25 lane)
- HNSW index on embedding, GIN index on fts

**`search_rcw_wac()` RPC**: hybrid vector + BM25 with Reciprocal Rank Fusion (k=60).
Filter params: `filter_corpus`, `filter_title`, `min_similarity`.

## Secrets

Uses `../.secrets/ohskey.php` (shared with ohs-search). Keys needed:
- `ANTHROPIC_API_KEY`
- `OPENAI_API_KEY`
- `SUPABASE_URL`
- `SUPABASE_ANON_KEY`

## Deployment

1. Run `ingestion/schema.sql` in Supabase SQL Editor
2. Ingest law XML files (see Ingestion section below)
3. Upload `index.html`, `api-proxy.php`, `src/` to `psd1.net/rcw-wac/` via cPanel
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

### New project checklist

1. Create account/project at supabase.com (free tier — separate from OHS Memory)
2. Enable the `vector` extension: Database → Extensions → search "vector" → Enable
3. Run `ingestion/schema.sql` in the SQL Editor
4. Copy `anon` key → into `rcwkey.php` in your `.secrets/` folder on the server
5. Copy `service_role` key → into a local `.env` file for running `ingest.py`
6. Never commit either key to git

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

Update `api-proxy.php` line that loads secrets: change `ohskey.php` → `rcwkey.php`.

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
export SUPABASE_URL=https://qawqovyqnvlcyuxezmrp.supabase.co
export SUPABASE_ANON_KEY=eyJ...

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

### Fixing the HTML parser

If `--dry-run` shows empty content or garbage, the legislature site changed its HTML structure.
Open `https://app.leg.wa.gov/RCW/default.aspx?cite=28A.400.010` in your browser, inspect the
element containing the section text, and update the CSS selectors in `rcw_fetch_section()`
(look for the list starting with `#contentWrapper`). Add the correct selector there.

### Estimated corpus size (Phase 1)
| Title | ~Sections | ~Chunks | Crawl time | Embed cost |
|-------|-----------|---------|------------|------------|
| RCW 28A | ~850 | ~1,000 | ~7 min | ~$0.04 |
| WAC 180 | ~600 | ~700 | ~5 min | ~$0.03 |
| RCW 42.56 | ~80 | ~100 | ~1 min | <$0.01 |
| **Total** | **~1,530** | **~1,800** | **~13 min** | **~$0.07** |

Full corpus (all ~96 RCW titles + all WAC): ~25,000 chunks, ~2-4 hours crawl, ~$1.00 embed.

## Model

`api-proxy.php` uses **Claude Haiku 4.5** (`claude-haiku-4-5-20251001`) for generation.
- Fast (~1s TTFB for a 2k-token context)
- Cheap (~$0.003/query at typical context sizes)
- Adequate for synthesis of retrieved legal text

To upgrade to Sonnet: change the model string in `api-proxy.php:define('SYSTEM_PROMPT'...` area.

## SSE Event Protocol

```
data: {"sources": [{section_id, section_heading, corpus, source_url, similarity_pct}, ...]}
data: {"text": "...streaming delta..."}
data: {"text": "..."}
...
data: {"meta": {inTokens, outTokens, cachedTokens, resultCount}}
data: [DONE]
```

Sources are emitted **before** text starts so the UI can render citation cards while Claude types.

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
```

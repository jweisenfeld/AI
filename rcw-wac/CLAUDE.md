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

## Ingestion

### Getting the XML

WA Legislature distributes RCW and WAC as XML. Download individual titles from:
- RCW: `https://apps.leg.wa.gov/rcw/` → navigate to a title → Download
- WAC: `https://apps.leg.wa.gov/wac/` → navigate to a title → Download

### Phase 1 (recommended starting point)
Education-specific titles relevant to PSD:
- **RCW Title 28A** — Common School Provisions (K-12 education law)
- **WAC Title 180** — Office of Superintendent of Public Instruction (OSPI rules)
- **RCW Title 42** — Public Officers & Agencies (public records, meetings)

### Running the ingestion script

```bash
cd rcw-wac/ingestion/
pip install -r requirements.txt

export OPENAI_API_KEY=sk-...
export SUPABASE_URL=https://qawqovyqnvlcyuxezmrp.supabase.co
export SUPABASE_ANON_KEY=eyJ...

# Dry run first (parse + chunk, no API calls)
python ingest.py rcw_title28A.xml --corpus rcw --dry-run

# Real ingest
python ingest.py rcw_title28A.xml --corpus rcw
python ingest.py wac_title180.xml --corpus wac

# Re-ingest a title (e.g. after law updates)
python ingest.py rcw_title28A.xml --corpus rcw --clear
```

### Estimated corpus size (Phase 1)
| Title | Sections | ~Chunks | Embed cost |
|-------|----------|---------|------------|
| RCW 28A | ~850 | ~1,000 | ~$0.04 |
| WAC 180 | ~600 | ~700 | ~$0.03 |
| RCW 42 | ~400 | ~450 | ~$0.02 |
| **Total** | **~1,850** | **~2,150** | **~$0.09** |

Full corpus (all RCW titles + all WAC titles): ~25,000 chunks, ~$1.00 to embed.

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
# Parse only — no API calls
python ingest.py title28A.xml --corpus rcw --dry-run

# Ingest new title
python ingest.py title28A.xml --corpus rcw

# Replace title (e.g. after law update)
python ingest.py title28A.xml --corpus rcw --clear

# Verify corpus in Supabase
# Run in SQL Editor: SELECT corpus, title_num, count(*) FROM rcw_wac_chunks GROUP BY 1,2 ORDER BY 1,2;
```

# pmc1/ — CLAUDE.md

## Purpose
Professional AI reference assistant for the Pasco Municipal Code (PMC).
Targeted at city engineers, planners, developers, and municipal staff — not students.
Deployed at psd1.net/pmc1 for sharing with Pasco city engineering staff.

## Architecture
Identical to coach5 — Gemini Files API + explicit context cache with SSE streaming.
The full PMC (~9.4MB HTML, ~1M tokens) is loaded as context on every query.
With explicit caching, cache hits cost ~4× less than uncached queries.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Professional chat UI — no login, no phase selector, PMC expert system prompt |
| `api-proxy.php` | Gemini proxy — streaming, caching, cold-start retry, query logging |
| `cache-ping.php` | Daily cron: re-uploads PMC to Gemini Files API (48hr TTL) |
| `cache-create.php` | One-time setup: uploads PMC and stores Files API URI in `.secrets/` |
| `Pasco-Municipal-Code.html` | Source PMC document (9.4MB) |
| `query_logs/` | Monthly query logs (`YYYY-MM.txt`), blocked from web access |
| `gemini_usage.log` | Per-request token usage, TTFB, cache hit/miss |

## Differences from coach5
- No student login or roster authentication
- No phase selector (6-part Engineering Design Process removed)
- No student logs or image moderation archive
- System prompt: professional PMC expert with citation standards (§X.XX.XXX format)
- maxOutputTokens raised to 2000 (professionals want complete answers)
- MAX_HISTORY_CHARS raised to 80,000 (longer professional sessions)
- WARN_TURNS raised to 20, STOP_TURNS to 30
- Query logs written to `query_logs/YYYY-MM.txt` instead of per-student files
- SESSION_ID (random, ephemeral) replaces STUDENT_ID for log correlation

## Deployment Notes
Same cPanel deployment as coach5. Requires:
- `.secrets/amentum_geminikey.php` — Gemini API key
- `.secrets/gemini_cache_name.txt` — Files API URI (written by cache-create.php)
- Daily cron running `cache-ping.php` to renew the 48-hour Files API TTL

## Architecture Decision: Why Not RAG?
The PMC is a single document. Gemini's 1M context + explicit cache makes direct-context
cost-competitive with RAG (embedding + pgvector + synthesis). RAG would be better if:
- Multiple documents need cross-referencing (amendment ordinances, state law, etc.)
- Exact section-level citation with quoted text is required
- A self-improving query-failure feedback loop is needed at scale

Phase 2 upgrade path: ingest PMC sections into Supabase (ohs-search architecture),
add amendment documents, council ordinances. Use hybrid vector + BM25 search.

## Self-Improvement Notes
- `query_logs/` captures all queries — review monthly for unanswered/poor queries
- These become the backlog for: prompt improvements, PMC gap identification,
  and future FAQ/RAG layer seeding
- To add a "flag as unanswered" button: add `log_flag` action to api-proxy.php,
  write to `query_logs/flagged.txt`

## Current Status
Active — first deployment for city engineer outreach (April 2026).

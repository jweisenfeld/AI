# lotf1/ — CLAUDE.md

## Purpose
Text Navigator chatbot for William Golding's *Lord of the Flies* (1954).
Used by students in a cross-disciplinary unit (ELA/Riley, Economics/Dunn, Physics/Weisenfeld).
Deployed at psd1.net/lotf1.

## Design Philosophy
This is deliberately NOT a tutor or essay-helper. It is a **text locator** — it finds
passages and quotes them. It does not interpret, summarize, or explain.

The system prompt enforces three rules:
1. Answer WHERE questions → quote the passage + cite the chapter
2. Reject WHY/interpretation/theme questions → brief decline with redirect
3. Redirect borderline questions (those with embedded judgments) → find neutral passages, let students judge

The system prompt is **hardcoded server-side** in `api-proxy.php` (constant `LOTF_SYSTEM_PROMPT`).
The client does NOT send the system prompt — this prevents students from bypassing restrictions
via browser dev tools. This is a key security difference from pmc1.

## Architecture
Same stack as pmc1: Gemini Files API + explicit context cache + SSE streaming.

| Component | Detail |
|-----------|--------|
| Document | `Lord-of-the-Flies.pdf` — must be placed in `lotf1/` folder |
| MIME type | `application/pdf` (hardcoded — no `.mime` hint file needed) |
| Models | `gemini-2.5-flash-lite` (Quick Search) and `gemini-2.5-flash` (Full Search) only |
| Explicit cache | Prefixed `lotf1_` in `.secrets/` to avoid collision with pmc1 |
| Files API URI | Stored in `.secrets/lotf1_cache_name.txt` |
| Explicit cache files | `.secrets/gemini_explicit_cache_lotf1_gemini-2-5-flash-lite.txt` etc. |

## Key Files

| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI — dark green theme, 2-model selector, no image upload |
| `api-proxy.php` | Gemini proxy — streaming, caching, system prompt hardcoded, query logging |
| `cache-create.php` | One-time setup: uploads PDF to Files API, saves URI to `.secrets/` |
| `cache-ping.php` | Daily cron: re-uploads PDF (48hr Files API TTL) |
| `Lord-of-the-Flies.pdf` | Source document — **not checked into git** (copyrighted) |
| `query_logs/` | Monthly query logs, blocked from web access |
| `gemini_usage.log` | Per-request token usage and cache hit/miss |

## Differences from pmc1

| Feature | pmc1 | lotf1 |
|---------|------|-------|
| Document | PMC HTML (~875K tokens) | LOTF PDF (~100K tokens) |
| System prompt source | Client-side JS | Server-side PHP constant |
| Models offered | 6 (Flash-Lite, Flash, Pro, 3.x previews) | 2 (Flash-Lite, Flash) |
| Image upload | Yes (site plan photos) | No (not needed) |
| KaTeX math | Yes | No |
| Cache file prefix | `gemini_explicit_cache_` | `gemini_explicit_cache_lotf1_` |
| Files API key | `gemini_cache_name.txt` | `lotf1_cache_name.txt` |
| MAX_HISTORY_CHARS | 80,000 | 40,000 |
| maxOutputTokens | 4,000 | 3,000 |

## Model Choice Rationale
2.5 Flash-Lite and 2.5 Flash are the only two models in `EXPLICIT_CACHE_MODELS_JSON`.
Both have confirmed working explicit caching. 3.x preview models were deliberately excluded:
- Not in pmc1's explicit cache list (untested/unconfirmed)
- Preview models can be deprecated mid-unit (gemini-3-pro-preview died March 9, 2026)
- Text retrieval doesn't require 3.x capabilities

## Deployment Steps

1. Place `Lord-of-the-Flies.pdf` in the `lotf1/` folder (not in git — copyrighted)
2. Ensure `.secrets/amentum_geminikey.php` exists on the server (shared with pmc1)
3. Visit `https://psd1.net/lotf1/cache-create.php?secret=amentum2025` to upload the PDF
4. Set up daily cron (3am) running `cache-ping.php` to renew the 48hr Files API TTL
5. Test a query; check `gemini_usage.log` for `FILE_URI` or `EXPLICIT_CACHE` flag

## Query Log Review
Review `query_logs/YYYY-MM.txt` monthly to identify:
- Questions students commonly ask (refine welcome message examples)
- Questions the bot incorrectly answers or declines (tune system prompt)
- Questions that reveal misunderstanding of the tool's purpose (teacher communication)

## Current Status
Created April 2026 for LOTF interdisciplinary unit (Riley/Dunn/Weisenfeld).
Not yet deployed — pending PDF placement and cache-create run.

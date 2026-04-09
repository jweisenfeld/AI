# pmc2/ — CLAUDE.md

## Purpose
Professional AI reference assistant for the Pasco Municipal Code (PMC).
Identical purpose to pmc1, but powered by **Anthropic Claude** instead of Google Gemini.
Intended for side-by-side comparison with pmc1 for city engineer demos.

## Models Available
| Model | Context | Cost/query (cached) | Notes |
|-------|---------|---------------------|-------|
| Claude Sonnet 4.6 (default) | 1M tokens | ~$0.30 | Best balance of speed + quality |
| Claude Opus 4.6 | 1M tokens | ~$0.50 | Highest quality, slower |

Note: Claude Haiku 4.5 is NOT offered — its 200k context window is too small
for the ~938k-token PMC document.

## Key Differences from pmc1

| Feature | pmc1 (Gemini) | pmc2 (Claude) |
|---------|--------------|---------------|
| Caching mechanism | Explicit cache (cron + cache-create.php) | Automatic prompt caching (cache_control) |
| Cache storage cost | ~$9/day (Gemini charges per hour) | $0 (no storage fee) |
| Cache discount | 75% off | 90% off |
| Context window | 1M (Flash/Pro) | 1M (Sonnet/Opus 4.6) |
| Color scheme | Navy blue (#1e3a5f) | Forest green (#1e3d2f) |
| Cron job needed | Yes | No |
| HISTORY format | { role: 'user'/'model', parts: [...] } | { role: 'user'/'assistant', content: string } |
| Fallback response | data.candidates[0].content.parts[0].text | data.content[0].text |

## Architecture
- **No Files API** — Claude doesn't have a separate file upload API for documents
- **No cron job** — prompt caching is automatic; no cache-create.php needed
- **PMC document** — read from disk on every request, sent with cache_control=ephemeral
  - Path: `../pmc1/Pasco-Municipal-Code-clean.txt` (shared with pmc1, single source)
  - First request: writes cache (1.25x cost); subsequent: reads at 10% of normal cost
- **SSE streaming** — Anthropic SSE events are parsed differently from Gemini:
  - `content_block_delta` events contain text deltas (delta.type === 'text_delta')
  - `message_start` event contains cache token counts
  - `message_delta` event contains final output token count

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Claude-specific chat UI — forest green theme, Sonnet/Opus dropdown |
| `api-proxy.php` | Anthropic Messages API proxy with prompt caching |
| `claude_usage.log` | Per-query token log (CacheRead, CacheWrite, CACHE_HIT/MISS) |
| `query_logs/` | Monthly query logs, web-access blocked |

## API Key
Uses `.secrets/claudekey.php` (shared with claude/ chatbot).
File returns: `['ANTHROPIC_API_KEY' => 'sk-ant-...']`

## Cache Badge Behavior (new in pmc2)
Three states vs. pmc1's two:
- ⚡ **cached Nk tok** (green) — cache read hit, 90% discount applied
- ✍ **wrote Nk tok** (amber) — cache written this request, next query gets discount
- ○ **no cache** (gray) — cache miss (shouldn't happen after first query)

## Deployment Notes
- Same cPanel server as pmc1 (psd1.net)
- No cron job required — remove any pmc2 cron if accidentally added
- Deploy: upload index.html + api-proxy.php to pmc2/ on server
- First query will be slower (cache write ~1.2s overhead) — subsequent queries fast

## Self-Improvement Notes
- `query_logs/` captures all queries — same monthly review workflow as pmc1
- Compare pmc1 vs pmc2 logs for the same questions to benchmark quality
- Claude's Constitutional AI training makes it more likely to say "I don't know"
  vs. Gemini's tendency to synthesize plausible-sounding but potentially wrong answers

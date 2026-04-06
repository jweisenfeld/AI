# lotf1/ — CLAUDE.md

## Purpose
Text Navigator chatbot for William Golding's *Lord of the Flies* (1954).
Used by students in a cross-disciplinary unit (ELA/Riley, Economics/Dunn, Physics/Weisenfeld).
Deployed at psd1.net/lotf1.

## Current Status
Deployed and working — April 2026. PDF uploaded, explicit cache active, daily cron running.

---

## ⚠️ Known Bug: "No response received" — The Explicit Cache Dud

**This cost 1 hour to debug. Read this first if the chatbot goes silent.**

### Symptom
The UI shows `⚠️ No response received. The server may be unavailable.` for every query.
The browser console shows the SSE stream completing with no text events.
`gemini_usage.log` shows entries with `Out:0` or `Out:1–5` on the affected requests.

### Root Cause
Google has an unresolved bug where Gemini returns a near-empty "dud" response (0–5 output
tokens) on cold-start requests — particularly when using **explicit context cache**. The response
comes back HTTP 200 with no error, but the candidates array contains only thinking tokens
(`thoughtSignature` parts) that the proxy filters out, resulting in zero emitted text.

The original auto-retry code (copied from pmc1) only retried on the **Files API path**:
```php
// BUG — this condition skips the retry entirely when explicit cache is active:
if ($fileUri !== null && $explicitCacheName === null) { ... retry ... }
```
Once `cache-create.php` runs successfully, `$explicitCacheName` is always set, so the retry
block **never fires**. Every cold-start dud on the explicit cache path → empty text → error.

### Fix Applied (April 2026)
The condition was removed. The retry now fires for **any** response with ≤ 5 output tokens,
regardless of which path (Files API or explicit cache) was used:
```php
// FIXED — retries on dud responses from either path:
if ($firstPassOut <= 5) {
    // retry...
    // logs: AUTO_RETRY | explicit-cache dud (Out:N, Cached:M) — retrying
}
```
The log will now show `AUTO_RETRY | explicit-cache dud` when this happens, making it
immediately visible.

### Why the Test Script Didn't Catch It
`test-stream.php` was run immediately after `cache-create.php` — the cache was freshly warm
and Gemini responded correctly every time. The dud only manifests after the cache has been
idle (cold start after ~5–10 min inactivity, or on the very first request to a new cache).
A proper regression test would need to wait for idle time before testing, or explicitly test
with a brand-new cache on the first request. We didn't do that.

### Same Bug Exists in pmc1
`pmc1/api-proxy.php` has the same unfixed condition. If pmc1 goes silent with the same
symptom, apply the identical fix there. The fix was only applied to lotf1 in April 2026.

---

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

---

## Architecture
Same stack as pmc1: Gemini Files API + explicit context cache + SSE streaming.

| Component | Detail |
|-----------|--------|
| Document | `Lord-of-the-Flies.pdf` — must be placed in `lotf1/` folder on server |
| MIME type | `application/pdf` (hardcoded — no `.mime` hint file needed) |
| Models | `gemini-2.5-flash-lite` (Quick Search) and `gemini-2.5-flash` (Full Search) only |
| Explicit cache | Prefixed `lotf1_` in `.secrets/` to avoid collision with pmc1 |
| Files API URI | Stored in `.secrets/lotf1_cache_name.txt` |
| Explicit cache files | `.secrets/gemini_explicit_cache_lotf1_gemini-2-5-flash-lite.txt` etc. |

---

## Key Files

| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI — dark green theme, 2-model selector, no image upload |
| `api-proxy.php` | Gemini proxy — streaming, caching, system prompt hardcoded, query logging |
| `cache-create.php` | One-time setup: uploads PDF to Files API, saves URI to `.secrets/` |
| `cache-ping.php` | Daily cron: re-uploads PDF (48hr Files API TTL) |
| `test-stream.php` | Diagnostic test suite — 8 tests covering the full pipeline |
| `Lord-of-the-Flies.pdf` | Source document — **not in git** (copyrighted) |
| `query_logs/` | Monthly query logs, blocked from web access |
| `gemini_usage.log` | Per-request token usage, cache hit/miss, AUTO_RETRY events |

---

## Diagnosing "No response received"

Work through these in order. Stop at the first failure.

### Step 1 — Run the test suite
`https://psd1.net/lotf1/test-stream.php?secret=amentum2025`

This runs 8 tests covering every layer of the pipeline. The first FAIL tells you the root cause.

### Step 2 — Check the usage log
`https://psd1.net/lotf1/gemini_usage.log`

Look for the pattern on the failing requests:
- `Out:0` or `Out:1–5` → dud response. Should now auto-retry (see bug above).
  If auto-retry isn't firing, check that the fix is deployed.
- `AUTO_RETRY | explicit-cache dud` → the fix is working; a retry happened.
- `NO_FILE` in the cache type column → Files API URI is missing or expired.
  Run `cache-create.php` or `cache-ping.php`.
- No log entry at all for the user's request → PHP is crashing before the Gemini call.
  Check PHP error logs in cPanel.

### Step 3 — Test the Gemini connection directly (no file, no cache)
POST to `api-proxy.php` with this body:
```json
{"action":"debug_chat","secret":"amentum2025","model":"gemini-2.5-flash-lite","message":"Reply with exactly three words: testing works correctly"}
```
If this fails, the API key is wrong or the model name is invalid.
If this succeeds but normal queries fail, the issue is in the PDF/cache layer.

### Step 4 — Check the Files API URI
In `gemini_usage.log`, look for `NO_FILE` or `FILE_URI` instead of `EXPLICIT_CACHE`.
If the URI is expired, run: `https://psd1.net/lotf1/cache-ping.php`
If the URI is missing entirely, run: `https://psd1.net/lotf1/cache-create.php?secret=amentum2025`

---

## Deployment Steps (first time or after server wipe)

1. Upload all PHP files and `Lord-of-the-Flies.pdf` to `lotf1/` via cPanel
2. Confirm `.secrets/amentum_geminikey.php` exists (shared with pmc1 — do not duplicate)
3. Visit `https://psd1.net/lotf1/cache-create.php?secret=amentum2025`
   — uploads the PDF, writes `lotf1_cache_name.txt`, prints confirmation
4. Set up daily cron at 3am: `/usr/local/bin/php /home2/fikrttmy/public_html/lotf1/cache-ping.php`
5. Visit `https://psd1.net/lotf1/test-stream.php?secret=amentum2025` — all 45 tests must pass
6. **Wait 10 minutes, then test a real query in the browser.** The test suite runs on a warm
   cache; the dud bug only appears on cold starts. A 10-minute idle then a fresh browser test
   is the only reliable way to confirm the auto-retry fix is working in production.

---

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
| Explicit cache dud fix | ❌ Not applied | ✅ Applied April 2026 |
| debug_chat route | ✅ | ✅ Added April 2026 |
| test-stream.php | ✅ | ✅ Added April 2026 |

---

## Model Choice Rationale
2.5 Flash-Lite and 2.5 Flash are the only two models in `EXPLICIT_CACHE_MODELS_JSON`.
Both have confirmed working explicit caching. 3.x preview models were deliberately excluded:
- Not in pmc1's explicit cache list (untested/unconfirmed)
- Preview models can be deprecated mid-unit (gemini-3-pro-preview died March 9, 2026)
- Text retrieval doesn't require 3.x capabilities

---

## Query Log Review
Review `query_logs/YYYY-MM.txt` monthly to identify:
- Questions students commonly ask (refine welcome message examples)
- Questions the bot incorrectly answers or declines (tune system prompt)
- Questions that reveal misunderstanding of the tool's purpose (teacher communication)

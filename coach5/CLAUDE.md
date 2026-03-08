# coach5/ — CLAUDE.md

## Purpose
Fifth-generation AI coaching tool for the Civil Engineering Argument Project.
Deployed to ~150 ninth-grade students at Orion High School, Pasco WA.
Acts as a Senior Engineering Mentor guiding students through the Engineering Design Process
anchored in Pasco municipal infrastructure and NGSS standards MS-ETS1-1 through MS-ETS1-4.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI (login, model/phase selectors, streaming chat) |
| `api-proxy.php` | Server-side Gemini API proxy — handles streaming, logging, image archive, cold-start retry |
| `cache-ping.php` | Daily cron job that re-uploads Pasco Municipal Code to Gemini Files API (48hr TTL) |
| `test-stream.php` | Unit test suite for SSE streaming, caching, and payload-size guard |
| `student_logs/` | Per-student conversation logs (`{studentID}.txt`) |
| `student_logs/images/` | Moderation archive of student-uploaded images (`.htaccess` blocks web access) |
| `gemini_usage.log` | Per-request token usage, TTFB, cache hit/miss, and auto-retry events |
| `Pasco-Municipal-Code-clean.*` | Cleaned municipal code file uploaded to Gemini Files API |
| `gemini_cache_name.txt` | (in `.secrets/`) Active Gemini Files API URI + expiry timestamp |

## Improvements over coach4

### System Prompt (MASTER_RUBRIC)
- **Tiered response length rules** — bot response length is capped by student input length
  (< 10 words → 100–150 words; 10–30 → 200–300; 30+ → 300–500; hard cap 500 words).
  Every response ends with exactly ONE follow-up question.
- **Phase announcement suppression** — phase header only shown on `[FIRST_MESSAGE]`;
  all follow-up turns (`[FOLLOW_UP]`) skip it and go straight to helping.
- **3-strike phase redirect protocol** — escalating response when students ask about
  a phase they haven't reached (redirect → limited ideas → full ideas + journal note).
- **Command resistance** — bot ignores "say X / repeat X / be quiet" meta-commands
  and redirects warmly to the engineering project.
- **Authorship reflection** — when polished/formal content is shared, bot prompts
  "Walk me through your thinking on [X]" rather than validating without reflection.
- **Image link handling** — if a student pastes an `<img>` tag or image URL instead of
  uploading, bot explains it can't see linked images and directs them to the upload button.

### Latency & Model
- **Default model changed to Gemini 2.5 Flash-Lite** for fastest TTFB (~5–6s on cache hits).
- **Model dropdown tooltip** hints students to switch to Flash for more detailed responses.
- **`maxOutputTokens: 800`** added to both streaming and non-streaming API payloads
  as a hard safety net (reduces runaway-long responses and lowers TTFB).
- **Cold-start auto-retry** in `api-proxy.php`: if the first response returns ≤5 output tokens
  with no cache hit (Flash-Lite cold-start bug on 1M-token context), the proxy retries
  immediately — the first call warms the KV cache so the retry almost always gets a
  `CACHE_HIT` and returns a real response. Retry events are logged to `gemini_usage.log`.

### Image Handling
- **Images dropped from HISTORY after sending** — base64 data is never stored in the
  in-memory history array; a text placeholder `[student uploaded N image(s)]` is used
  instead. This prevents the context-limit false-positive that blocked image uploads.
- **Context-limit guard fixed** — now measures text-only `[phasePrefix, ...HISTORY]`
  rather than the full payload (which could include multi-MB base64 image data).
- **Image moderation archive** — every student-uploaded image is saved server-side to
  `student_logs/images/{studentID}_{timestamp}_{n}.{ext}` after a successful API call,
  for teacher review. Directory is blocked from direct web access via `.htaccess`.

### Session Turn Tracking
- `[FIRST_MESSAGE]` / `[FOLLOW_UP]` flag injected into the phase context prefix on every
  request, giving the model a reliable signal for phase announcement suppression.

### Logging
- `logToTeacher()` now records image uploads in the student log:
  e.g. `USER: here's my design [+ 1 image(s)]`
- `gemini_usage.log` includes `IMAGE_SAVED` lines and `AUTO_RETRY` lines for full audit trail.

## Current Status
Active — most recent coaching tool in use (supersedes coach4).

# claude/ — CLAUDE.md

## Purpose
Full-featured Claude AI chat interface for students, deployed at psd1.net/claude.
The most developed AI interface in the repo with analytics dashboard, model configuration,
and automatic model updates.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI (~66KB) |
| `api-proxy.php` | Server-side proxy — forwards Anthropic-tier requests to `api.anthropic.com`, GLM to Z.AI, Kimi K3 to Moonshot (both OpenAI-compatible) |
| `dashboard.php` | Teacher analytics dashboard — usage statistics and cost per student |
| `dashboard.html` | Dashboard frontend |
| `model_config.json` | Model configuration with pricing tiers (Haiku / Sonnet / Opus via Anthropic, GLM via Z.AI, Kimi K3 via Moonshot) |
| `update_models.php` | Fetches latest model list from Anthropic API and updates config (skips non-Anthropic tiers like `glm`/`kimi`) |
| `tests.js` / `tests.php` | Test suite for the interface |

## How It Works
1. Student opens `index.html` in browser
2. Student types a message → JS sends it to `api-proxy.php`
3. `api-proxy.php` adds the API key and forwards to `api.anthropic.com` (Haiku/Sonnet/Opus),
   `api.z.ai` (GLM), or `api.moonshot.ai` (Kimi K3)
4. Response streams back to the browser
5. Usage and per-interaction cost are logged for the dashboard

## Message Rendering
`formatMessage()` in index.html is a hand-rolled markdown renderer (no library). Fenced code
blocks are pulled out **before** any other pass and rendered as a `.code-block` panel: a header
bar with the language label and a **Copy** button (`copyCodeBlock()`, clipboard API with an
execCommand fallback) over a `<pre><code>` that preserves indentation.

The closing fence is **optional** by design. Replies that hit the output-token cap stop
mid-code, and an unterminated fence used to leak the whole program into the markdown passes —
`# comments` became `<h1>` headings, indentation collapsed, and students couldn't copy the code.
An unclosed block now renders as code and is labeled "cut off, ask continue"; `api-proxy.php`
also maps OpenAI `finish_reason: length` to `stop_reason: max_tokens` so the frontend can show a
tip.

**Failed requests roll back the user turn.** index.html pushes the user's message into `messages`
before sending. If the request fails (any error, or an empty answer) no assistant reply follows
it, and the next send would put two user turns in a row — which the Anthropic API rejects
("roles must alternate"), bricking the conversation until Clear Chat. `rollBackUserTurn()`
removes that turn **by identity**, and hands the student's text and attachments back to the
composer (without clobbering anything they typed or attached since). Their message bubble stays
on screen as a record, so the error text says the message wasn't added to the conversation.

**Empty answers.** An OpenAI-compatible response can be 2xx with `content: ""` — reasoning-style
models (DeepSeek Vision is the observed case) write chain-of-thought into `reasoning_content` and
only then write the answer, so a budget that runs out mid-thought bills full output tokens and
returns nothing. `normalizeOpenAiCompatibleResponse()` turns empty/whitespace content into an
`empty_response` error that still carries `usage` (so cost logging survives) and records
`reasoning_chars` + `finish_reason` in `claude_usage.log`. index.html has a matching backstop for
the Anthropic path and never pushes an empty assistant turn into `messages`.

index.html requests `max_tokens: 8192` (raised from 4096, which cut off full-program answers).
8192 is the proxy's hard ceiling — `api-proxy.php` clamps with `min($requested, 8192)` and still
defaults to 4096 when a client omits the field, which is what tests.js/tests.php assert. Only
tokens actually generated are billed, so the higher cap costs nothing until a reply needs it.

## Restriction Banner
A banner above the chat states what the account can do *right now*: subject-only /
Socratic-tutor mode from `topic_lock`, whether a free window is open, and whether the
school-hours rule is forcing every tier to Haiku. `buildRestrictionStatus()` in api-proxy.php
computes it **server-side** and returns it from `verify_login`, `validate_session`, and every
chat reply — the state is time-dependent (a topic lock lifts inside a free window; the Haiku
limit starts at 5 PM), so a banner drawn once at login goes stale mid-session, and computing it
in JS would mean reimplementing `isWithinAllowedHours()` there. Amber when something is
narrowing what the student can do, blue for a free window, hidden for unrestricted accounts.

## Per-Model Quirks Live in model_config.json
When a model has an idiosyncrasy, encode it as a **field**, not a branch. Kimi K3 rejects every
temperature except 1 (400: "invalid temperature: only 1 is allowed for this model"), so its tier
carries `"fixed_temperature": 1` and `buildOpenAiCompatibleRequest()` applies it generically —
a second model with the same constraint is a config edit, not a code change. Same principle as
`supportsVision`. Resist adding per-provider codepaths: every bug found so far (unclosed code
fences, token-cap truncation, empty answers, unpaired user turns) was shared across providers
and got one shared fix.

## Timeouts
`API_TIMEOUT_SECONDS` (240) caps both cURL calls; PHP gets that plus 60s via `set_time_limit()`.
The old 120s ceiling was too tight once `max_tokens` went to 8192 — GLM timed out mid-program
with "0 bytes received". This proxy is **non-streaming**, so the whole answer is generated before
any byte returns; that wait is inherent without a streaming rewrite. `describeConnectionFailure()`
reports timeouts as `model_timeout` with advice to ask for one piece at a time, rather than the
misleading "check your API configuration".

## Smoke Test
`smoke-test.py` sends one short code prompt per tier through the **deployed** proxy and reports
which model answered, `stop_reason`, empty answers, whether a fenced code block survived intact,
tokens and estimated cost. Tiers are read from model_config.json, so new ones are covered
automatically. Credentials come from `CHATBOT_USER`/`CHATBOT_PASS` or the gitignored
`.smoke-test-credentials.json` — never committed. Requests need a browser User-Agent or
psd1.net's ModSecurity returns 406. Run it after deploying, and remember that outside school
hours a non-unlimited account tests Haiku eight times.

## Security
API keys are stored server-side only — never exposed to the browser.
`.htaccess` restricts direct access to PHP config files.

## Model Tiers
`model_config.json` defines which models are available and their relative costs, one entry per tier.
Each tier's `provider` field controls which backend api-proxy.php calls: `anthropic` (default,
Anthropic Messages API), `zai` (Z.AI's OpenAI-compatible endpoint, GLM tier), or `moonshot`
(Moonshot's OpenAI-compatible endpoint, Kimi tier). `zai`/`moonshot` share one generic code path
in api-proxy.php (`$EXTERNAL_PROVIDERS`, `buildOpenAiCompatibleRequest`/`callOpenAiCompatibleApi`/
`normalizeOpenAiCompatibleResponse`) — adding a third OpenAI-compatible provider means adding one
entry to `$EXTERNAL_PROVIDERS` plus a secrets file, not new call/parse code.
Anthropic tiers get auto-healing model fallback and `update_models.php` support; external-provider
tiers do not (single model, no fallback list, and `update_models.php` skips them — those models
are verified manually).

GLM is the default model tier — it replaced the old Fable tier (removed for cost reasons) as the
free-to-students, no-restriction default. Both GLM and Kimi K3 are text-only for now: image/vision
requests on either tier are rejected server-side with a message to switch to Sonnet or Opus, since
this proxy doesn't translate Anthropic-style image content blocks into OpenAI's `image_url` format
(Kimi K3 itself has native vision support — this is a proxy limitation, not a model one).

Each external provider's API key lives in its own secrets file next to `claudekey.php` (outside
`public_html`): `.secrets/zaikey.php` returns `['ZAI_API_KEY' => '...']`, `.secrets/kimikey.php`
returns `['KIMI_API_KEY' => '...']`. If a tier's key file is missing, that tier fails closed with
a 500 rather than falling back silently.

## Cost Tracking
Every logged interaction includes `cost_usd`, computed in api-proxy.php as
`tokens/1e6 * pricing_rate` per model_config.json's `input_per_mtok`/`output_per_mtok` for the
requested tier (auto-healed Anthropic fallbacks are priced at the tier's *current* rate, not
that snapshot's original rate — an acceptable approximation for a rare edge case). dashboard.php
prefers each entry's stored `cost_usd`; its own COSTS table (keyed by exact model ID, matching
model_config.json) is only a fallback for log rows written before cost tracking existed.

GLM's pricing (`0.995`/`0.995`, i.e. a flat $0.995/MTok blended rate) reflects what was actually
paid ($19.90 for a 20M-token balance), not Z.AI's published list price (input $1.40 / output $4.40
per MTok) — if usage turns out to be metered separately from that balance rather than at a flat
bulk rate, split `input_per_mtok`/`output_per_mtok` back apart. Kimi's pricing (`3.00`/`15.00`) is
Moonshot's standard/cache-miss rate from platform.kimi.ai/docs/pricing/chat-k3; their cheaper
$0.30/MTok cache-hit rate isn't modeled since this proxy sends no cache hints.

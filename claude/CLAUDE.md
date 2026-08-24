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

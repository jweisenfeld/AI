# claude/ — CLAUDE.md

## Purpose
Full-featured Claude AI chat interface for students, deployed at psd1.net/claude.
The most developed AI interface in the repo with analytics dashboard, model configuration,
and automatic model updates.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI (~66KB) |
| `api-proxy.php` | Server-side proxy — forwards Anthropic-tier requests to `api.anthropic.com`, GLM requests to Z.AI's OpenAI-compatible endpoint |
| `dashboard.php` | Teacher analytics dashboard — usage statistics per student |
| `dashboard.html` | Dashboard frontend |
| `model_config.json` | Model configuration with pricing tiers (Haiku / Sonnet / Opus via Anthropic, GLM via Z.AI) |
| `update_models.php` | Fetches latest model list from Anthropic API and updates config (skips non-Anthropic tiers like `glm`) |
| `tests.js` / `tests.php` | Test suite for the interface |

## How It Works
1. Student opens `index.html` in browser
2. Student types a message → JS sends it to `api-proxy.php`
3. `api-proxy.php` adds the API key and forwards to `api.anthropic.com` (Haiku/Sonnet/Opus) or `api.z.ai` (GLM)
4. Response streams back to the browser
5. Usage is logged for the dashboard

## Security
API keys are stored server-side only — never exposed to the browser.
`.htaccess` restricts direct access to PHP config files.

## Model Tiers
`model_config.json` defines which models are available and their relative costs, one entry per tier.
Each tier's `provider` field controls which backend api-proxy.php calls: `anthropic` (default,
Anthropic Messages API) or `zai` (Z.AI's OpenAI-compatible endpoint, GLM tier only).
Anthropic tiers get auto-healing model fallback and `update_models.php` support; the `zai`
tier does not (single model, no fallback list, and `update_models.php` skips it — Z.AI models
are verified manually).

GLM is the default model tier — it replaced the old Fable tier (removed for cost reasons) as the
free-to-students, no-restriction default. GLM is text-only for now: image/vision requests on
that tier are rejected server-side with a message to switch to Sonnet or Opus, since the
OpenAI-compatible endpoint doesn't accept Anthropic-style image content blocks.

The Z.AI API key lives in `.secrets/zaikey.php` (same directory as `claudekey.php`, outside
`public_html`), returning `['ZAI_API_KEY' => '...']`. If that file is missing, the `glm` tier
fails closed with a 500 rather than falling back silently.

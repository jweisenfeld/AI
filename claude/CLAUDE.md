# claude/ — CLAUDE.md

## Purpose
Full-featured Claude AI chat interface for students, deployed at psd1.net/claude.
The most developed AI interface in the repo with analytics dashboard, model configuration,
and automatic model updates.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat UI (~66KB) |
| `api-proxy.php` | Server-side proxy — receives student requests, forwards to Anthropic API |
| `dashboard.php` | Teacher analytics dashboard — usage statistics per student |
| `dashboard.html` | Dashboard frontend |
| `model_config.json` | Model configuration with pricing tiers (Haiku / Sonnet / Opus) |
| `update_models.php` | Fetches latest model list from Anthropic API and updates config |
| `tests.js` / `tests.php` | Test suite for the interface |

## How It Works
1. Student opens `index.html` in browser
2. Student types a message → JS sends it to `api-proxy.php`
3. `api-proxy.php` adds the API key and forwards to `api.anthropic.com`
4. Response streams back to the browser
5. Usage is logged for the dashboard

## Security
API key is stored server-side only — never exposed to the browser.
`.htaccess` restricts direct access to PHP config files.

## Model Tiers
`model_config.json` defines which Claude models are available and their relative costs.
Run `update_models.php` to sync with the latest available models.

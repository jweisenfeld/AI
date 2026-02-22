# chatgpt2/ — CLAUDE.md

## Purpose
Updated ChatGPT (OpenAI) interface — the current version replacing `chatgpt/`.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Chat interface (~43KB, more feature-rich than v1) |
| `api-proxy.php` | Server-side OpenAI API proxy |
| `openaikey.php` | API key management (include file, not directly accessible) |

## Notes
Follows the same architecture pattern as the Claude interfaces.
`openaikey.php` is included by `api-proxy.php` and should be restricted via `.htaccess`.

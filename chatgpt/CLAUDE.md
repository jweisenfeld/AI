# chatgpt/ — CLAUDE.md

## Purpose
First-generation ChatGPT (OpenAI) interface for students. Older codebase maintained for
compatibility; `chatgpt2/` is the updated version.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Chat UI |
| `app.js` | Frontend JavaScript logic |
| `chat_api.php` | Backend API handler |
| `chat_api_utils.php` | Utility functions for API calls |
| `styles.css` | Interface styling |
| `tests.html` / `tests.js` / `tests.php` | Test suite |

## Architecture
Same proxy pattern as Claude interfaces — `chat_api.php` holds the OpenAI API key
server-side and proxies requests from the browser.

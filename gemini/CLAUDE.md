# gemini/ — CLAUDE.md

## Purpose
Google Gemini AI interface for students — in active development as of February 2026.
Follows the same architecture pattern as the Claude and ChatGPT interfaces.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat interface (~19KB) |
| `index.php` | Backend handler / API proxy (updated Feb 19, 2026) |

## Status
In development. Most recently updated file in the repo as of Feb 2026.

## Architecture
Same pattern as other AI interfaces:
- `index.html` — frontend UI
- `index.php` — server-side proxy keeping Google API key off the client

# claude2/ — CLAUDE.md

## Purpose
Simplified Claude AI interface — a leaner version of `claude/` designed for easy
deployment on any cPanel host. See `README.md` for setup instructions.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing chat interface |
| `api-proxy.php` | Server-side proxy to Anthropic Claude API |
| `user-database.csv` | User tracking database |
| `README.md` | Setup guide for cPanel deployment |

## Setup (from README.md)
1. Upload files to cPanel web hosting
2. Edit `api-proxy.php` to insert your Anthropic API key
3. Point students to the URL

## Difference from claude/
- No analytics dashboard
- No model configuration UI
- Simpler codebase — easier to customize or repurpose

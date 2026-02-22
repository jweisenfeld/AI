# coach3/ — CLAUDE.md

## Purpose
Third-generation AI coaching tool with caching, streaming, and a reference document
(Pasco Municipal Code). More production-ready than coach2.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Coaching interface |
| `api-proxy.php` | Claude API proxy with streaming support |
| `amentum_report.php` | Session report generator |
| `cache-create.php` | Creates a prompt cache for repeated context |
| `cache-ping.php` | Checks if cached context is still valid |
| `strip-html.php` | Strips HTML from reference documents for cleaner AI context |
| `list-models.php` | Lists available Claude models |
| `test-stream.mjs` / `test-stream.php` | Streaming response tests |
| `Pasco-Municipal-Code.html` | ~9.4MB reference document used as AI context |
| `student_logs/` | Per-session activity logs |
| `.htaccess` | Restricts access to sensitive PHP files |

## Features Added Over coach2
- **Caching** — reduces API costs by caching the large municipal code context
- **Streaming** — real-time token streaming for better UX
- **Reference document** — AI has access to the Pasco Municipal Code
- **Activity logging** — per-student session logs in `student_logs/`

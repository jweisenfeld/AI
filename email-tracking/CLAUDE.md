# email-tracking/ — CLAUDE.md

## Purpose
Tracks whether students open emails by embedding a 1×1 transparent tracking pixel.
Provides a dashboard showing open rates, multi-open counts, and device info.

## Key Files
| File | Description |
|------|-------------|
| `track.php` | Serves the tracking pixel; logs each open to the database |
| `track-v2.php` / `track-v3.php` | Updated tracking versions |
| `track-debug.php` | Debug version with verbose logging |
| `dashboard.php` | Password-protected open-rate dashboard (~15KB) |
| `api/record_sent.php` | API endpoint called by EmailDropBox to log sent emails |
| `export_unopened.php` | Exports list of students who haven't opened the email |
| `schema.sql` | Database schema — run once to create tables |
| `backfill-email-sent.sql` | SQL to backfill historical send records |
| `fix-foreign-key.sql` | Schema migration for foreign key fix |
| `update-credentials.py` | Python helper to update tracking database credentials |
| `README.md` | System overview |
| `SETUP.md` | Detailed setup instructions |
| `QUICK-START.md` | Quick start guide |

## How It Works
1. EmailDropBox embeds `<img src="https://psd1.net/email-tracking/track.php?id={studentID}&campaign={name}">` in each email
2. When student opens email, browser loads the pixel → `track.php` logs the open
3. Dashboard at `psd1.net/email-tracking/dashboard.php` shows results
4. Use `export_unopened.php` to get a list for follow-up

## Database
MySQL database with tables for: campaigns, recipients, open events.
See `schema.sql` for the full schema.

## Setup
1. Deploy to cPanel with PHP + MySQL
2. Run `schema.sql` to create tables
3. Edit `track.php` to set database credentials
4. See `SETUP.md` for complete instructions

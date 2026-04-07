# pmc1 Operations Runbook

This file documents day-to-day operations, monitoring, and recovery workflows for the Pasco Municipal Code chatbot.

## 1) Logging and Health Signals

- `cache-ping.log`  
  Daily cron output for Files API re-upload activity.
- `gemini_usage.log`  
  Request-level telemetry (tokens, cache flags, TTFB lines).
- `query_logs/YYYY-MM.txt`  
  Query/response logs captured by `api-proxy.php` `log_query` action.
- Admin dashboard (`admin.html`)  
  Includes live cache health panel + live query report.

## 2) Scheduled Jobs (recommended)

### A. Daily cache refresh (required)

Run every day at 3:00 AM UTC:

```cron
0 3 * * * /usr/local/bin/php /home2/fikrttmy/public_html/pmc1/cache-ping.php >> /home2/fikrttmy/public_html/pmc1/cache-ping.log 2>&1
```

### B. Hourly monitor + email alerting (recommended)

Run every hour:

```cron
0 * * * * /usr/bin/flock -n /tmp/pmc1-monitor-hourly.lock /usr/local/bin/php /home2/fikrttmy/public_html/pmc1/monitor-hourly.php >> /home2/fikrttmy/public_html/pmc1/monitor-hourly.log 2>&1
```

## 3) SMTP Setup for Email Notifications

Expected credentials file:

`/.secrets/smtp_credentials.php`

With variables:

- `$SMTP_HOST`
- `$SMTP_PORT`
- `$SMTP_USER`
- `$SMTP_PASS`
- `$SMTP_FROM`
- `$SMTP_FROM_NAME`
- `$TEACHER_CC` (optional)

`monitor-hourly.php` sends to `jweisenfeld@psd1.org` and CCs `$TEACHER_CC` when present.

## 4) Optional Synthetic Probe URL

To run a real chat probe each hour, add:

`/.secrets/monitor_base_url.txt`

Example content:

```text
https://psd1.net/pmc1
```

If this file is absent, the monitor skips the synthetic probe and still checks cache freshness/expiry.

## 5) Incident Response

If monitor email reports FAIL:

1. Open `admin.html` and review cache health + live query report.
2. Run `cache-ping.php?secret=...` manually.
3. If still failing or cache expired, run `cache-create.php?secret=...`.
4. Re-test with `test-stream.php?secret=...`.
5. Verify `gemini_usage.log` has `FILE_URI` / expected cache flags.

## 6) Documentation Practice (recommended)

Keep this file as the **single runbook** for:

- cron schedules
- alerting setup
- recovery steps
- key file locations

When behavior changes, update this runbook in the same commit as the code change.

# Admin Setup Guide - Student Authentication & Cost Tracking

## Overview

The Claude AI interface now includes:

1. **Student Authentication** - Students must log in with ID and password
2. **Request Logging** - All API calls are logged with student ID, costs, and timestamps
3. **Cost Tracking** - Real-time cost calculation for each request
4. **Admin Dashboard** - Monitor usage, costs, and detect anomalies

---

## Files Created/Modified

### New Files:
- `auth.php` - Handles student login/logout
- `admin-dashboard.php` - Admin interface for monitoring usage
- `/Misc/25-26-S2-Passwords-Combined.csv` - Student credentials (sample included)
- `/claude/logs/student_requests.jsonl` - Detailed request logs (auto-created)

### Modified Files:
- `index.html` - Added login screen and authentication
- `api-proxy.php` - Added student tracking, cost calculation, logging

---

## Setup Instructions

### Step 1: Update Student Password File

Edit `/Misc/25-26-S2-Passwords-Combined.csv` with your actual student data:

```csv
Id,Password
1001,BlueSky42
1002,GreenLeaf88
1003,RedRocket99
```

**Important:** Keep this file SECURE and above your public_html directory!

### Step 2: Set Admin Dashboard Password

Edit `admin-dashboard.php` line 10:

```php
$ADMIN_PASSWORD = 'YOUR_SECURE_PASSWORD_HERE';
```

Change this to a strong password!

### Step 3: Update Model Pricing (if needed)

The costs in `api-proxy.php` (lines 153-165) are set for Jan 2026 pricing:

| Model | Input (per 1M tokens) | Output (per 1M tokens) |
|-------|----------------------|------------------------|
| Sonnet 4 | $3.00 | $15.00 |
| Opus 4 | $15.00 | $75.00 |
| Sonnet 4.5 | $3.00 | $15.00 |
| Opus 4.5 | $15.00 | $75.00 |

Update these if Anthropic changes pricing.

---

## How It Works

### Student Login Flow:

1. Student visits `index.html`
2. Sees login screen
3. Enters Student ID and Password
4. `auth.php` validates against CSV
5. Session created, student gains access
6. Student ID displayed in header
7. All requests tagged with student ID

### Request Logging:

Every API call logs:
- Timestamp
- Student ID
- Session ID
- Model used
- IP address
- Token counts (input/output)
- Calculated costs
- Message preview (first 200 chars)
- Response time
- Success/failure

Logs stored in: `claude/logs/student_requests.jsonl`

### Cost Calculation:

Formula:
```
Input Cost = (input_tokens / 1,000,000) × price_per_million_input
Output Cost = (output_tokens / 1,000,000) × price_per_million_output
Total Cost = Input Cost + Output Cost
```

---

## Using the Admin Dashboard

### Access:
Visit: `https://your-domain.com/path/to/claude/admin-dashboard.php`

### Features:

1. **Overview Stats**
   - Total requests
   - Total costs
   - Active students
   - Average cost per request

2. **Student Usage Summary**
   - Requests per student
   - Total tokens per student
   - Total cost per student
   - Average cost per request

3. **Recent Requests Table**
   - Timestamp
   - Student ID
   - Model used
   - Token usage
   - Cost
   - Message preview (for anomaly detection)

4. **Filters**
   - Filter by specific student
   - Filter by time period (24h, 7d, 30d, all time)

5. **Export**
   - Download raw JSONL log file for deeper analysis

---

## Monitoring for Anomalies

### Red Flags to Watch:

1. **Unusually High Costs**
   - Student with >$5 in a day (depends on your budget)
   - Single request costing >$1

2. **Off-Topic Queries**
   - Message previews not about bus shelters
   - Generic homework help
   - Personal conversations

3. **Suspicious Patterns**
   - Student making 100+ requests in short time
   - Identical messages repeated
   - Requests outside school hours (if applicable)

### Response Actions:

1. Review the message preview in dashboard
2. Check full log file if needed
3. Contact student if concerns
4. Adjust their access if necessary
5. Update system prompt to redirect behavior

---

## Budget Management

### Example Budget Scenarios:

**Scenario 1: 140 Individual Students**
- Budget: $500
- Per student: ~$3.57
- ~50-100 requests per student (depending on model)

**Scenario 2: 40 Groups (3-4 students each)**
- Budget: $500
- Per group: $12.50
- ~150-250 requests per group

### Recommended Limits:

Set expectations with students:
- Use Sonnet 4.5 for most tasks (cheaper)
- Reserve Opus 4.5 for complex problems
- Typical request cost: $0.02-$0.10

### Cost Alerts:

Monitor dashboard daily/weekly and look for:
- Total cost approaching budget
- Individual students exceeding fair share
- Sudden cost spikes

---

## API Key Question

**Do you need a new API key for updated model names?**

**Answer: NO!** ✅

Your existing Anthropic API key works with all models, including:
- claude-sonnet-4-20250514
- claude-opus-4-20250514
- claude-sonnet-4-5-20250929 ← New!
- claude-opus-4-5-20251101 ← New!

The API key is model-agnostic. You just specify which model in your request.

**However**, check your Anthropic account:
1. Has the new models available (they should be)
2. Has sufficient rate limits for your student usage
3. Has billing set up correctly

---

## Security Checklist

- [ ] Changed admin dashboard password from default
- [ ] Student CSV file is above public_html (not web-accessible)
- [ ] Anthropic API key in /.secrets/ (not web-accessible)
- [ ] Tested student login works
- [ ] Tested admin dashboard access
- [ ] Reviewed first few student requests
- [ ] Set up billing alerts in Anthropic dashboard

---

## Troubleshooting

### Students can't log in
- Check CSV file path in `auth.php`
- Verify CSV format (no extra spaces, correct columns)
- Check file permissions

### Logs not appearing
- Check `claude/logs/` folder exists and is writable
- Verify PHP has write permissions
- Check api-proxy.php error logs

### Costs seem wrong
- Verify pricing in api-proxy.php matches Anthropic's current rates
- Check token counts in Anthropic dashboard vs. logs

### Admin dashboard won't load
- Check you changed the password
- Verify session support enabled in PHP
- Check file permissions

---

## Support & Questions

For issues with:
- **Authentication**: Check `auth.php` and CSV file
- **Logging**: Check `api-proxy.php` and logs directory
- **Costs**: Verify pricing in `api-proxy.php`
- **Dashboard**: Check `admin-dashboard.php` password

Review the README.md in the claude folder for general setup.

---

## Next Steps for Premium Version

If you want to enhance this further:

1. **Prompt Caching** - Save 90% on repeated context (system prompts)
2. **Streaming Responses** - Better UX, see responses in real-time
3. **PDF Upload Support** - Students upload building codes, research papers
4. **Better Markdown Rendering** - Use marked.js library
5. **Team Sharing** - Students share conversations within their group
6. **Extended Thinking** - Enable Opus 4.5's deep reasoning mode
7. **Rate Limiting** - Prevent abuse with per-student daily limits

Let me know if you'd like help implementing any of these!

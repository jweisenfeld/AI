# Email Tracking System

**Invisible pixel tracking for student emails**

Track when students open your emails using a 1x1 transparent tracking pixel. View real-time statistics in a clean dashboard.

## 🚀 Quick Start

1. **Set up database** - Follow instructions in `SETUP.md`
2. **Upload files** - Git push to main branch (auto-deploys to psd1.net)
3. **Test system** - Run `send-tracked-test-email.py` from Misc repo
4. **View dashboard** - https://psd1.net/email-tracking/dashboard.php

## 📁 Files

- **`track.php`** - Tracking pixel endpoint (serves 1x1 GIF and logs opens)
- **`dashboard.php`** - View email statistics and open rates
- **`api/record_sent.php`** - API to record sent emails from Python
- **`schema.sql`** - Database schema (run in phpMyAdmin)
- **`SETUP.md`** - Complete setup instructions

## 🔧 How It Works

1. Python script sends email with embedded tracking pixel:
   ```html
   <img src="https://psd1.net/email-tracking/track.php?id=UNIQUE_ID" width="1" height="1" />
   ```

2. When student opens email, their email client loads the image

3. `track.php` receives the request and logs:
   - Email ID
   - Timestamp
   - IP address
   - User agent (device/client)

4. Dashboard displays statistics:
   - Total sent / opened
   - Open rate percentage
   - Individual email status
   - Time of first open

## 📊 Dashboard Features

- **Password protected** (default: `physics2026`)
- **Real-time statistics**
- **Color-coded status** (Opened = green, Not Opened = red)
- **Sort by date**
- **Track multiple opens** per email
- **Responsive design**

## 🔒 Privacy & Security

- Dashboard is password-protected
- Database credentials not in Git (update after deployment)
- Optional: Disable IP logging in `track.php` if desired
- Consider adding tracking disclosure to syllabus/email footer

## 🎯 Integration Example

Add tracking to any existing email script:

```python
import uuid
import requests
from datetime import datetime

# Generate unique ID
email_id = str(uuid.uuid4())

# Add pixel to HTML email
tracking_pixel = f'<img src="https://psd1.net/email-tracking/track.php?id={email_id}" width="1" height="1" style="display:none;" />'
html_body = your_content + tracking_pixel

# Record sent email
requests.post("https://psd1.net/email-tracking/api/record_sent.php", json={
    'email_id': email_id,
    'student_id': '12345',
    'recipient_email': 'student@psd1.org',
    'recipient_name': 'Student Name',
    'subject': 'Your Subject',
    'sent_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
})

# Send via Outlook
mail.HTMLBody = html_body
mail.Send()
```

## 📈 Future Enhancements

- [ ] Auto-follow-up for unopened emails after X days
- [ ] Device/client breakdown (mobile vs desktop)
- [ ] Geographic heatmap from IP addresses
- [ ] A/B testing for subject lines
- [ ] Export data to CSV
- [ ] Email open time distribution chart
- [ ] Integration with all existing email scripts

## 🆘 Troubleshooting

See `SETUP.md` for detailed troubleshooting steps.

**Common issues:**
- Database connection failed → Check credentials in PHP files
- Pixel not tracking → Email client blocking external images
- Dashboard not loading → Check file permissions and database

---

**Dashboard:** https://psd1.net/email-tracking/dashboard.php
**Test Script:** `C:\Users\johnw\.claude-worktrees\Misc\distracted-poincare\send-tracked-test-email.py`

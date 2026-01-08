# Email Tracking - Quick Start Checklist

**Goal:** Track when jweisenfeldtest@students.psd1.org opens test email

## ⚡ Speed Run (15 minutes)

### Step 1: Database Setup (5 min)
- [ ] cPanel → MySQL Databases
- [ ] Create database: `flkrttmy_email_tracking` (note actual name)
- [ ] Create user: `flkrttmy_tracker` with strong password
- [ ] Add user to database with ALL PRIVILEGES
- [ ] phpMyAdmin → Select database → SQL tab
- [ ] Paste entire contents of `schema.sql` → Click Go
- [ ] Verify 2 tables + 1 view created

### Step 2: Update Code (3 min)
Update in **3 files** (`track.php`, `dashboard.php`, `api/record_sent.php`):

```php
$DB_NAME = 'actual_database_name_from_step1';
$DB_USER = 'actual_username_from_step1';
$DB_PASS = 'actual_password_from_step1';
```

Also in `dashboard.php` line 16:
```php
$DASHBOARD_PASSWORD = 'your_secure_password';  // Change from default
```

### Step 3: Deploy (2 min)
```bash
cd ~/Documents/GitHub/AI
git add email-tracking/
git commit -m "Add email tracking system"
git push origin main
```

Wait 1-2 minutes for auto-deploy.

### Step 4: Verify Deployment (1 min)
Visit: https://psd1.net/email-tracking/track.php?id=test

Should see: Tiny pixel or blank page (that's correct!)

### Step 5: Send Test Email (1 min)
```bash
cd ~/.claude-worktrees/Misc/distracted-poincare
python send-tracked-test-email.py
```

Should see:
```
✓ Tracking record created
✓ Email sent successfully!
```

### Step 6: Verify Email Received (1 min)
- [ ] Log in to jweisenfeldtest@students.psd1.org
- [ ] Email received in inbox
- [ ] Email has nice HTML formatting
- [ ] **DO NOT OPEN YET**

### Step 7: Check Dashboard "Not Opened" (1 min)
- [ ] Visit: https://psd1.net/email-tracking/dashboard.php
- [ ] Enter password
- [ ] Dashboard shows:
  - Total Sent: 1
  - Total Opened: 0
  - Open Rate: 0%
  - Table shows email with **RED "Not Opened"** badge

### Step 8: Open Email (1 min)
- [ ] In jweisenfeldtest inbox, **OPEN the email**
- [ ] Read the content (triggers pixel load)

### Step 9: Verify Tracking Works (1 min)
- [ ] Refresh dashboard: https://psd1.net/email-tracking/dashboard.php
- [ ] Dashboard now shows:
  - Total Sent: 1
  - Total Opened: 1
  - Open Rate: 100%
  - Table shows email with **GREEN "Opened"** badge
  - Shows timestamp of when you opened it
  - Shows "Opens: 1"

## 🎉 Success!

If all checks pass, your tracking system is working!

## 🔧 If Something Breaks

### Dashboard shows database error
→ Check credentials in PHP files match database

### Pixel not recording opens
→ Open email in different client (Gmail web, Outlook desktop)
→ Check phpMyAdmin → email_opens table for entries

### Email not sending
→ Check Outlook is running and configured
→ Verify recipient email is correct

### API returns 500
→ Check database credentials in `api/record_sent.php`
→ View error in cPanel Error Logs

## 📱 Next Steps

After confirming it works:

1. **Test multiple opens** - Open email again, refresh dashboard (count should increment)
2. **Test from phone** - Forward email to personal device, open there
3. **Integrate into real script** - Add tracking to failing grade emails
4. **Monitor open rates** - Use data to optimize communication timing

---

**Full documentation:** See SETUP.md
**Integration guide:** See EMAIL-TRACKING-SUMMARY.md in Misc repo

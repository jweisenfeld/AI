# Email Tracking System - Setup Guide

This system tracks when students open your emails by embedding an invisible 1x1 pixel image that logs open events to your MySQL database.

## 📋 Setup Checklist

### Step 1: Create MySQL Database (via cPanel)

1. **Log into cPanel** at your Bluehost hosting
2. **Navigate to "MySQL Databases"**
3. **Create a new database:**
   - Database name: `flkrttmy_email_tracking` (or whatever cPanel assigns)
   - Note the full database name (usually has your username prefix)

4. **Create a new MySQL user:**
   - Username: `flkrttmy_tracker` (or your choice)
   - Password: Generate a strong password and **save it securely**
   - Note the full username (usually has your username prefix)

5. **Add user to database:**
   - Select the database you created
   - Select the user you created
   - Grant **ALL PRIVILEGES**
   - Click "Add"

### Step 2: Run Database Schema (via phpMyAdmin)

1. **In cPanel, click "phpMyAdmin"**
2. **Select your `flkrttmy_email_tracking` database** from the left sidebar
3. **Click the "SQL" tab** at the top
4. **Copy and paste the contents of `schema.sql`** into the query box
5. **Click "Go"** to execute
6. **Verify tables were created:**
   - You should see tables: `email_sent` and `email_opens`
   - You should see a view: `email_stats`

### Step 3: Update PHP Files with Database Credentials

Edit the following files and update the database credentials:

#### `track.php` (lines 13-15):
```php
$DB_NAME = 'flkrttmy_email_tracking';  // Your actual database name
$DB_USER = 'flkrttmy_tracker';         // Your actual database user
$DB_PASS = 'your_password_here';       // Your actual password
```

#### `dashboard.php` (lines 11-13):
```php
$DB_NAME = 'flkrttmy_email_tracking';  // Same as above
$DB_USER = 'flkrttmy_tracker';         // Same as above
$DB_PASS = 'your_password_here';       // Same as above
```

#### `dashboard.php` (line 16) - Change dashboard password:
```php
$DASHBOARD_PASSWORD = 'physics2026';  // Change this to something secure!
```

#### `api/record_sent.php` (lines 18-20):
```php
$DB_NAME = 'flkrttmy_email_tracking';  // Same as above
$DB_USER = 'flkrttmy_tracker';         // Same as above
$DB_PASS = 'your_password_here';       // Same as above
```

### Step 4: Upload Files to Server

You mentioned your GitHub\AI repo auto-deploys to psd1.net via YML.

**Option A: Via Git (Recommended)**
1. Commit the `email-tracking` folder to your GitHub\AI repo
2. Push to GitHub
3. Wait for auto-deployment to psd1.net
4. Verify files are at: `https://psd1.net/email-tracking/`

**Option B: Via cPanel File Manager**
1. In cPanel, go to "File Manager"
2. Navigate to `public_html` (or your web root)
3. Create folder: `email-tracking`
4. Upload all files from your local `email-tracking` folder
5. Upload the `api` subfolder with `record_sent.php`

### Step 5: Set File Permissions

In cPanel File Manager or via SSH:
```bash
chmod 644 track.php
chmod 644 dashboard.php
chmod 644 api/record_sent.php
chmod 755 email-tracking/
chmod 755 email-tracking/api/
```

### Step 6: Test the Tracking Endpoint

Visit in your browser:
```
https://psd1.net/email-tracking/track.php?id=test123
```

**Expected result:** You should see a tiny 1x1 pixel (might look like nothing, that's correct!)

**Verify in database:** Go to phpMyAdmin → `email_opens` table → should have 1 row with `email_id = 'test123'`

### Step 7: Test the Dashboard

Visit:
```
https://psd1.net/email-tracking/dashboard.php
```

**Expected:**
1. Login screen (password: `physics2026` or whatever you changed it to)
2. Dashboard shows statistics
3. Should show 0 emails sent (or your test entry)

### Step 8: Send Test Email

1. **On your local machine**, navigate to your Misc repo:
   ```bash
   cd C:\Users\johnw\.claude-worktrees\Misc\distracted-poincare
   ```

2. **Run the test script:**
   ```bash
   python send-tracked-test-email.py
   ```

3. **Expected output:**
   ```
   Sending tracked email...
     To: jweisenfeldtest@students.psd1.org
     Subject: 🔬 Email Tracking Test - Please Open
     Tracking ID: [some UUID]
     ✓ Tracking record created
     ✓ Email sent successfully!
   ```

4. **Check the test student inbox:**
   - Log in to jweisenfeldtest@students.psd1.org
   - Open the email
   - (This triggers the tracking pixel)

5. **View the dashboard:**
   - Visit `https://psd1.net/email-tracking/dashboard.php`
   - You should see:
     - Total Sent: 1
     - Total Opened: 1 (after opening the email)
     - Open Rate: 100%
     - Table showing the email with "Opened" status

## 🎉 Success Criteria

You know it's working when:

✅ Test email arrives in jweisenfeldtest inbox
✅ Email looks nice with HTML formatting
✅ Dashboard shows email in "Not Opened" status initially
✅ **After opening the email**, dashboard updates to "Opened" status
✅ Dashboard shows correct timestamp for when email was opened
✅ Open count increments if you open the email multiple times

## 🔧 Troubleshooting

### Dashboard shows "Database error"
- Check database credentials in PHP files
- Verify database exists in phpMyAdmin
- Check user has correct privileges

### Tracking pixel not recording opens
- Check `email_opens` table in phpMyAdmin for errors
- Look at server error logs in cPanel
- Verify `track.php` is accessible at the URL
- Some email clients block external images by default

### API endpoint returns 500 error
- Check database credentials in `api/record_sent.php`
- Verify `email_sent` table exists
- Check PHP error logs

### Email not sending from Python script
- Verify Outlook is installed and configured
- Check recipient email address is correct
- Try sending without tracking first (remove tracking pixel)

## 📊 How to Use in Production

Once tested and working, you can integrate tracking into any of your existing email scripts:

```python
import uuid

# Generate tracking ID
email_id = str(uuid.uuid4())

# Add tracking pixel to your HTML email
tracking_pixel = f'<img src="https://psd1.net/email-tracking/track.php?id={email_id}" width="1" height="1" style="display:none;" />'
body_html = your_email_content + tracking_pixel

# Record in database (optional, for dashboard)
requests.post("https://psd1.net/email-tracking/api/record_sent.php", json={
    'email_id': email_id,
    'student_id': student_id,
    'recipient_email': email,
    'recipient_name': first_name,
    'subject': subject,
    'sent_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
})

# Send email as normal
mail.HTMLBody = body_html
mail.Send()
```

## 🔒 Security Notes

- Dashboard is password-protected (change default password!)
- Database credentials should never be committed to Git
- Consider using environment variables for sensitive data
- IP addresses are logged but can be disabled if privacy is a concern
- Students/parents are not explicitly notified of tracking (add to syllabus/policy)

## 📝 Next Steps

After confirming this works:
1. Integrate into your existing email scripts
2. Monitor open rates to optimize communication timing
3. Create automated follow-ups for unopened critical emails
4. Build more advanced analytics (time-to-open, device breakdown, etc.)

---

**Need help?** Check the dashboard at https://psd1.net/email-tracking/dashboard.php

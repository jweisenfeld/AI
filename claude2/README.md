# Community Engineering AI Assistant - Setup Guide

## Quick Setup (5 minutes)

### Step 1: Configure PHP Version
1. In cPanel, click **MultiPHP Manager**
2. Find your domain in the list
3. Select **PHP 8.1** or higher from the dropdown
4. Click **Apply**

### Step 2: Upload Files
1. In cPanel, click **File Manager**
2. Navigate to `public_html` (or a subdirectory like `public_html/ai-assistant`)
3. Click **Upload** and upload both files:
   - `index.html`
   - `api-proxy.php`

### Step 3: Add Your API Key
1. In File Manager, right-click `api-proxy.php` → **Edit**
2. Find this line near the top:
   ```php
   $API_KEY = 'YOUR_API_KEY_HERE';
   ```
3. Replace `YOUR_API_KEY_HERE` with your actual Anthropic API key
4. Click **Save Changes**

### Step 4: Test It
Visit your site: `https://yourdomain.com/` (or `/ai-assistant/` if you used a subdirectory)

---

## Getting Your Anthropic API Key

1. Go to https://console.anthropic.com/
2. Sign in or create an account
3. Click **API Keys** in the sidebar
4. Click **Create Key**
5. Copy the key (starts with `sk-ant-...`)

---

## Setting Up Team Workspaces (For Budget Tracking)

To give each team their own $25 budget:

1. In the Anthropic Console, click **Workspaces**
2. Click **Create Workspace**
3. Name it (e.g., "Team-01-BusStop")
4. Set a **Spend Limit** of $25
5. Go to that workspace's **API Keys** tab
6. Create a new key for that workspace
7. Repeat for each team

Each team gets their own `api-proxy.php` file with their unique key, OR you build a team selection dropdown (advanced).

---

## Security Notes

- The API key is stored server-side (never exposed to browsers)
- All requests are logged to `claude_usage.log` in the same directory
- Students cannot see or access the API key
- The system prompt keeps conversations focused on the project

---

## Troubleshooting

**"API key not configured" error:**
- Make sure you edited `api-proxy.php` and replaced `YOUR_API_KEY_HERE`

**Blank page or 500 error:**
- Check that PHP 8.1+ is enabled in MultiPHP Manager
- Check File Manager for a file called `error_log` for details

**CORS or fetch errors:**
- Make sure both files are in the same directory
- Make sure the files are named exactly `index.html` and `api-proxy.php`

---

## Features

✅ Model selection (Sonnet 4 / Opus 4)
✅ Image upload (multimodal)
✅ Conversation history (saved in browser)
✅ Multiple projects
✅ Export to Markdown, Text, or HTML
✅ Token usage tracking
✅ Focused system prompt for bus shelter project
✅ Mobile-responsive design

---

## File Structure

```
public_html/
├── index.html          # The student interface
├── api-proxy.php       # Handles API calls (contains your key)
└── claude_usage.log    # Auto-created, logs all requests
```

---

## Questions?

This interface was built to support the Pasco School District Community Engineering project.
For technical issues, contact your project coordinator.

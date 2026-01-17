# GitHub Actions Deployment Setup

This repository uses GitHub Actions to automatically deploy to your web server.

## Deployment Workflow

- **Test Branch** → `claude-test/` folder → http://psd1.net/claude-test
- **Main Branch** → `claude/` folder → http://psd1.net/claude (production)

## How It Works

1. **Test Deployment**: When you push to `claude/review-html-premium-client-a03L4`, GitHub automatically deploys to `claude-test/`
2. **Production Deployment**: When you merge to `main`, GitHub automatically deploys to `claude/` (production)

---

## Initial Setup

### Step 1: Generate SSH Key Pair

On your local machine or server, generate a new SSH key specifically for deployments:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_deploy_key
```

This creates:
- `~/.ssh/github_deploy_key` (private key - for GitHub)
- `~/.ssh/github_deploy_key.pub` (public key - for server)

### Step 2: Add Public Key to Server

Copy the public key to your web server:

```bash
# Display the public key
cat ~/.ssh/github_deploy_key.pub

# Copy it to clipboard (macOS)
cat ~/.ssh/github_deploy_key.pub | pbcopy

# Copy it to clipboard (Linux with xclip)
cat ~/.ssh/github_deploy_key.pub | xclip -selection clipboard
```

Then add it to your server's `~/.ssh/authorized_keys`:

```bash
# SSH into your server
ssh your-username@psd1.net

# Add the public key
echo "paste-public-key-here" >> ~/.ssh/authorized_keys

# Set proper permissions
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

### Step 3: Test SSH Connection

From your local machine, test the connection:

```bash
ssh -i ~/.ssh/github_deploy_key your-username@psd1.net

# If it works, you're ready to proceed!
```

### Step 4: Add GitHub Secrets

Go to your GitHub repository:
1. Click **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret**

Add these secrets:

#### SSH_PRIVATE_KEY
```
Content: The entire private key from github_deploy_key
```

To get it:
```bash
cat ~/.ssh/github_deploy_key
```

Copy the **entire** output including:
```
-----BEGIN OPENSSH PRIVATE KEY-----
...
-----END OPENSSH PRIVATE KEY-----
```

#### SSH_HOST
```
Value: psd1.net
```
(or your server's IP address)

#### SSH_USER
```
Value: your-ssh-username
```
(the username you SSH with)

#### DEPLOY_PATH_TEST
```
Value: /home/your-username/public_html/claude-test
```
(adjust based on your server's directory structure)

#### DEPLOY_PATH_PROD
```
Value: /home/your-username/public_html
```
(this will deploy to `public_html/claude/`)

---

## Server Directory Structure

After deployment, your server should look like:

```
/home/your-username/
├── public_html/
│   ├── claude/                    # Production (from main branch)
│   │   ├── index.html
│   │   ├── auth.php
│   │   ├── api-proxy.php
│   │   ├── admin-dashboard.php
│   │   ├── logs/                  # Created automatically
│   │   └── ...
│   └── claude-test/               # Test (from feature branch)
│       ├── claude/
│       │   ├── index.html
│       │   ├── auth.php
│       │   └── ...
│       └── Misc/
│           └── 25-26-S2-Passwords-Combined.csv
├── AI/                            # Git repository (above public_html)
│   └── Misc/
│       └── 25-26-S2-Passwords-Combined.csv
└── .secrets/
    └── anthropic.php              # API key (referenced by both test and prod)
```

---

## Testing the Deployment

### Deploy Test Branch

```bash
# Make a small change and push
echo "# Test deployment" >> claude/README.md
git add claude/README.md
git commit -m "Test: trigger deployment"
git push origin claude/review-html-premium-client-a03L4
```

Then:
1. Go to GitHub → **Actions** tab
2. Watch the "Deploy to Test Server" workflow run
3. Once complete, visit: http://psd1.net/claude-test/claude/

### Monitor Deployment

In GitHub Actions, you'll see:
- ✅ Each step completing
- 📊 Deployment summary
- 🚨 Any errors

---

## Manual Deployment

You can also trigger deployments manually:

1. Go to **Actions** tab in GitHub
2. Select "Deploy to Test Server" or "Deploy to Production Server"
3. Click **Run workflow**
4. Choose the branch
5. Click **Run workflow**

---

## Workflow Files

### Test Deployment
`.github/workflows/deploy-test.yml`
- Triggers on push to `claude/review-html-premium-client-a03L4`
- Deploys to `DEPLOY_PATH_TEST/claude/`

### Production Deployment
`.github/workflows/deploy-production.yml`
- Triggers on push to `main`
- Deploys to `DEPLOY_PATH_PROD/claude/`

---

## What Gets Deployed

Both workflows deploy:
- ✅ `claude/` folder (all files)
- ✅ `Misc/` folder (student passwords)
- ✅ Creates `logs/` directory (if missing)

Both workflows exclude:
- ❌ `.git/` (version control)
- ❌ `.github/` (workflow files)
- ❌ `logs/` (preserve existing logs on server)
- ❌ `claude_usage.log` (preserve existing logs)
- ❌ `node_modules/` (if any)

---

## Important Notes

### Security

1. **Never commit the private key** to the repository
2. Store it only in GitHub Secrets
3. The private key is deleted after each deployment
4. Use a dedicated SSH key (not your personal key)

### Student Passwords

The `Misc/25-26-S2-Passwords-Combined.csv` file will be deployed to both test and production.

**Before going live**, update it with real student credentials!

### API Keys

The deployment assumes your `.secrets/anthropic.php` file exists on the server at:
```
/home/your-username/.secrets/anthropic.php
```

Make sure it's readable by your web server user:
```bash
chmod 644 ~/.secrets/anthropic.php
```

### First Deployment

On first deployment, you may need to:

1. **Create the test directory manually**:
```bash
ssh your-username@psd1.net
mkdir -p public_html/claude-test
```

2. **Verify permissions**:
```bash
chmod 755 public_html/claude-test
```

---

## Troubleshooting

### "Permission denied (publickey)"

- Verify public key is in `~/.ssh/authorized_keys` on server
- Check permissions: `chmod 600 ~/.ssh/authorized_keys`
- Ensure SSH_PRIVATE_KEY secret contains the **entire** private key

### "rsync: failed to set times"

- Ignore this - it's just a warning about file timestamps
- Files are still deployed correctly

### "No such file or directory"

- Check DEPLOY_PATH_TEST and DEPLOY_PATH_PROD are correct
- Create directories manually if needed

### Workflow doesn't trigger

- Verify you pushed to the correct branch name
- Check the branch name in the workflow file matches exactly
- Try manual trigger from Actions tab

### Files not updating on server

- Check the workflow completed successfully in Actions tab
- Verify you're looking at the correct URL
- Clear browser cache
- Check file modification times on server

---

## Example: Full Test-to-Production Flow

```bash
# 1. Work on feature branch
git checkout claude/review-html-premium-client-a03L4

# 2. Make changes
vim claude/index.html

# 3. Commit and push (auto-deploys to test)
git add .
git commit -m "Update login screen styling"
git push origin claude/review-html-premium-client-a03L4

# 4. Review at http://psd1.net/claude-test/claude/

# 5. If looks good, create PR to main
gh pr create --base main --head claude/review-html-premium-client-a03L4

# 6. Merge PR (auto-deploys to production)
gh pr merge

# 7. Production now live at http://psd1.net/claude/
```

---

## Updating Deployment Targets

To deploy different branches to test:

Edit `.github/workflows/deploy-test.yml`:
```yaml
on:
  push:
    branches:
      - 'claude/review-html-premium-client-a03L4'  # Change this
      - 'feature/new-branch'                        # Or add more
```

---

## Cost & Performance

- **GitHub Actions**: 2,000 free minutes/month for private repos (unlimited for public)
- **Deployment time**: ~30-60 seconds
- **No server-side dependencies**: Uses rsync (standard on most servers)

---

## Alternative: FTP Deployment

If your server doesn't support SSH, you can use FTP instead.

See `.github/workflows/deploy-test-ftp.yml.example` for an FTP-based workflow.

---

## Questions?

- **GitHub Secrets not working?** Double-check there are no extra spaces or newlines
- **Need to deploy to different paths?** Update the secrets in GitHub Settings
- **Want to deploy other branches?** Edit the workflow files
- **Need help?** Check the Actions logs for detailed error messages

---

Last Updated: 2026-01-17

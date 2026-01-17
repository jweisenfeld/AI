# Deployment Quick Start

## 🚀 What You Need (5 min setup)

### 1. Generate SSH Key
```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key
```

### 2. Add Public Key to Server
```bash
# Show public key
cat ~/.ssh/github_deploy_key.pub

# SSH to server and add it
ssh your-user@psd1.net
echo "paste-the-public-key-here" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### 3. Add GitHub Secrets

Go to: **GitHub Repo → Settings → Secrets and variables → Actions → New repository secret**

| Secret Name | Value | How to Get It |
|-------------|-------|---------------|
| `SSH_PRIVATE_KEY` | Your private key | `cat ~/.ssh/github_deploy_key` |
| `SSH_HOST` | `psd1.net` | Your server hostname/IP |
| `SSH_USER` | `your-username` | Your SSH username |
| `DEPLOY_PATH_TEST` | `/home/user/public_html/claude-test` | Test deployment path |
| `DEPLOY_PATH_PROD` | `/home/user/public_html` | Production path |

**Note**: For `SSH_PRIVATE_KEY`, copy the **entire** key including:
```
-----BEGIN OPENSSH PRIVATE KEY-----
...entire key...
-----END OPENSSH PRIVATE KEY-----
```

---

## ✅ Test It Works

```bash
# Push to trigger test deployment
git push origin claude/review-html-premium-client-a03L4
```

Then:
1. Go to **GitHub → Actions** tab
2. Watch the workflow run
3. Visit: http://psd1.net/claude-test/claude/

---

## 🔄 Deployment Flow

```
Feature Branch Push
    ↓
GitHub Actions Runs
    ↓
Deploys to claude-test/
    ↓
Review at http://psd1.net/claude-test/claude/
    ↓
Create PR to main
    ↓
Merge PR
    ↓
GitHub Actions Runs
    ↓
Deploys to production
    ↓
Live at http://psd1.net/claude/
```

---

## 📁 What Gets Deployed

✅ Deployed:
- All files in `claude/` folder
- All files in `Misc/` folder
- Creates `logs/` directory if missing

❌ Excluded:
- `.git/` directory
- `.github/` directory
- Existing `logs/` contents (preserved)
- `node_modules/`

---

## 🛠️ Common Commands

### Manual Deploy
```bash
# Go to GitHub → Actions → Select workflow → Run workflow
```

### Check What's on Server
```bash
ssh your-user@psd1.net
ls -la public_html/claude-test/claude/
```

### View Deployment Logs
```bash
# In GitHub → Actions → Click on latest workflow run
```

---

## 🚨 Troubleshooting

| Problem | Solution |
|---------|----------|
| Permission denied | Check public key is in server's `~/.ssh/authorized_keys` |
| Workflow doesn't run | Verify branch name matches workflow file |
| Files not updating | Check Actions tab for errors, clear browser cache |
| "No such file or directory" | Create target directory on server first |

---

## 📚 Full Documentation

See `.github/DEPLOYMENT-SETUP.md` for complete setup instructions and troubleshooting.

---

## ⚡ TL;DR

1. Generate SSH key
2. Add public key to server
3. Add 5 secrets to GitHub
4. Push code
5. Auto-deployed! 🎉

---

Need help? Check the Actions logs in GitHub for detailed error messages.

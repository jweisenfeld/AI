# Configure GitHub Secrets - Step by Step

The deployment failed because GitHub Secrets need to be configured first. Follow these steps:

---

## 📍 Step 1: Go to GitHub Secrets Settings

1. Go to your GitHub repository: https://github.com/jweisenfeld/AI
2. Click **Settings** (top menu)
3. In left sidebar, click **Secrets and variables** → **Actions**
4. Click **New repository secret** (green button)

---

## 🔑 Step 2: Add Each Secret

You need to add **5 secrets**. For each one:

### Secret 1: SSH_HOST

- **Name**: `SSH_HOST`
- **Value**: `psd1.net` (or your server IP/hostname)

Click **Add secret**

---

### Secret 2: SSH_USER

- **Name**: `SSH_USER`
- **Value**: Your SSH username (the username you use to SSH into psd1.net)

Example: If you SSH with `ssh john@psd1.net`, your username is `john`

Click **Add secret**

---

### Secret 3: DEPLOY_PATH_TEST

- **Name**: `DEPLOY_PATH_TEST`
- **Value**: Full path to test folder on your server

Common examples:
```
/home/your-username/public_html/claude-test
/home2/username/public_html/claude-test
/var/www/html/claude-test
```

To find your path, SSH into your server and run:
```bash
cd public_html
pwd
# Output shows your path, add /claude-test to it
```

Click **Add secret**

---

### Secret 4: DEPLOY_PATH_PROD

- **Name**: `DEPLOY_PATH_PROD`
- **Value**: Full path to production folder on your server

Common examples:
```
/home/your-username/public_html
/home2/username/public_html
/var/www/html
```

This is typically the same as DEPLOY_PATH_TEST but WITHOUT the `/claude-test` at the end.

Click **Add secret**

---

### Secret 5: SSH_PRIVATE_KEY

This is the most important one. You need to generate an SSH key pair first.

#### On your local computer, run:

```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key
```

Press Enter for no passphrase (required for automated deployment)

#### View the private key:

```bash
cat ~/.ssh/github_deploy_key
```

You'll see something like:
```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
...many lines of random characters...
AAAAC2dpdGh1Yi1kZXBsb3kBAgMEBQ==
-----END OPENSSH PRIVATE KEY-----
```

#### Copy the ENTIRE key including the BEGIN and END lines

#### Add the public key to your server:

```bash
# Show public key
cat ~/.ssh/github_deploy_key.pub

# Copy it
```

Then SSH into your server:
```bash
ssh your-username@psd1.net

# Add the public key
echo "paste-the-public-key-here" >> ~/.ssh/authorized_keys

# Fix permissions
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

#### Now add the SECRET in GitHub:

- **Name**: `SSH_PRIVATE_KEY`
- **Value**: Paste the ENTIRE private key (including BEGIN and END lines)

**Important**: Make sure there are no extra spaces or newlines at the start or end!

Click **Add secret**

---

## ✅ Step 3: Verify Secrets

After adding all 5 secrets, you should see them listed:

```
SSH_HOST
SSH_USER
SSH_PRIVATE_KEY
DEPLOY_PATH_TEST
DEPLOY_PATH_PROD
```

---

## 🧪 Step 4: Test the Configuration

### Option A: Run the secret checker

1. Go to **Actions** tab in GitHub
2. Click **Check Deployment Secrets** (left sidebar)
3. Click **Run workflow** → **Run workflow**
4. Wait for it to complete
5. Check the logs - should see all green checkmarks ✅

### Option B: Try deployment again

Just push a commit and the deployment will retry:

```bash
git commit --allow-empty -m "Retry deployment with secrets configured"
git push origin claude/review-html-premium-client-a03L4
```

---

## 🚨 Troubleshooting

### "Permission denied (publickey)"

**Problem**: Public key not added to server correctly

**Solution**:
```bash
ssh your-username@psd1.net
cat ~/.ssh/authorized_keys
# Make sure your public key is in there
chmod 600 ~/.ssh/authorized_keys
```

### "Invalid format" or "Bad key"

**Problem**: Private key copied incorrectly

**Solution**:
- Delete the `SSH_PRIVATE_KEY` secret in GitHub
- Run `cat ~/.ssh/github_deploy_key` again
- Copy it carefully (use Ctrl+A in terminal, then Ctrl+C)
- Make sure to include the BEGIN and END lines
- Paste into GitHub with no extra spaces

### Path doesn't exist

**Problem**: DEPLOY_PATH_TEST or DEPLOY_PATH_PROD is wrong

**Solution**:
```bash
# SSH into server
ssh your-username@psd1.net

# Find your web root
cd public_html
pwd
# Copy this path

# Create test directory if needed
mkdir -p public_html/claude-test
```

Then update the secret with the correct path.

---

## 📋 Quick Reference

If you know your server details, here's the quick version:

```bash
# Generate key
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key

# Add public key to server
ssh-copy-id -i ~/.ssh/github_deploy_key.pub your-user@psd1.net

# Get private key for GitHub
cat ~/.ssh/github_deploy_key
```

Then add these 5 secrets in GitHub Settings → Secrets → Actions:

1. `SSH_HOST` = psd1.net
2. `SSH_USER` = your-username
3. `SSH_PRIVATE_KEY` = (entire private key)
4. `DEPLOY_PATH_TEST` = /home/username/public_html/claude-test
5. `DEPLOY_PATH_PROD` = /home/username/public_html

---

## ✨ After Configuration

Once all secrets are added:

1. Push any commit to your branch
2. GitHub Actions will automatically deploy
3. Visit http://psd1.net/claude-test/claude/ to see your test site
4. Review everything
5. When ready, merge to main for production deployment

---

## 🆘 Still Having Issues?

Check the GitHub Actions logs for specific error messages:
- Go to **Actions** tab
- Click on the failed workflow
- Click on the failed step
- Read the error message

Common issues:
- Missing a secret → Add it
- Wrong path → Update the path secret
- SSH key issues → Regenerate and re-add keys
- Server permissions → Check folder permissions on server

---

Need more help? See `.github/DEPLOYMENT-SETUP.md` for the complete guide.

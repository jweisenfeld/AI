# SSH Key Troubleshooting for GitHub Actions

If you're seeing "error in libcrypto" or "Permission denied" errors in GitHub Actions, the SSH private key likely has formatting issues.

---

## 🔍 Common Issues

### Error: "Load key: error in libcrypto"

This means the private key format is incorrect in GitHub Secrets.

### Error: "Too many authentication failures"

SSH tried multiple keys and all failed. Your key format is wrong.

---

## ✅ How to Fix: Regenerate and Add Key Correctly

### Step 1: Generate a Fresh SSH Key

On your local computer:

```bash
# Remove old key if exists
rm -f ~/.ssh/github_deploy_key ~/.ssh/github_deploy_key.pub

# Generate new key (NO PASSPHRASE!)
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key

# When prompted for passphrase, just press Enter (leave it empty)
```

### Step 2: View the Private Key

```bash
cat ~/.ssh/github_deploy_key
```

You should see something like:
```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACDxyz5vF9fF7xUq0J9K3jZqU6tJxQY8s8kF3P7FqPQvIgAAAJgR6HKUE
... (many more lines) ...
AAAAC2dpdGh1Yi1kZXBsb3kBAgMEBQ==
-----END OPENSSH PRIVATE KEY-----
```

**Key Points:**
- It MUST start with `-----BEGIN OPENSSH PRIVATE KEY-----`
- It MUST end with `-----END OPENSSH PRIVATE KEY-----`
- There should be NO PASSPHRASE (we left it empty)
- Each line should be a separate line (not `\n` characters)

### Step 3: Copy the Private Key Correctly

**Option A: Using pbcopy (macOS)**
```bash
cat ~/.ssh/github_deploy_key | pbcopy
```

**Option B: Using xclip (Linux)**
```bash
cat ~/.ssh/github_deploy_key | xclip -selection clipboard
```

**Option C: Manual copy**
```bash
cat ~/.ssh/github_deploy_key
```
Then:
1. Select ALL text (including BEGIN and END lines)
2. Copy it (Ctrl+C or Cmd+C)
3. **DO NOT** add or remove any characters
4. **DO NOT** add extra spaces or newlines

### Step 4: Update GitHub Secret

1. Go to: https://github.com/jweisenfeld/AI/settings/secrets/actions
2. Find `SSH_PRIVATE_KEY` in the list
3. Click the pencil icon to edit (or delete and recreate)
4. Paste the ENTIRE key
5. **Important**: Make sure there are NO extra characters before `-----BEGIN` or after `-----END`
6. Click **Update secret**

### Step 5: Add Public Key to Server (If Not Done)

```bash
# Show public key
cat ~/.ssh/github_deploy_key.pub

# Copy it, then SSH to your server
ssh your-username@psd1.net

# Add the public key (replace with your actual key)
echo "ssh-ed25519 AAAA...your-public-key... github-deploy" >> ~/.ssh/authorized_keys

# Fix permissions
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
exit
```

### Step 6: Test Locally First

Before trying GitHub Actions, test the key locally:

```bash
ssh -i ~/.ssh/github_deploy_key your-username@psd1.net

# If this works, the key is good!
# If it fails, fix your server setup before trying GitHub
```

### Step 7: Retry Deployment

```bash
git commit --allow-empty -m "Retry with fixed SSH key"
git push origin claude/review-html-premium-client-a03L4
```

Watch the Actions tab - it should work now!

---

## 🔧 Alternative: Use Different Key Format

If ed25519 doesn't work, try RSA:

```bash
# Generate RSA key instead
ssh-keygen -t rsa -b 4096 -C "github-deploy" -f ~/.ssh/github_deploy_key_rsa

# Follow the same steps above with this key
```

---

## 🚨 Common Mistakes

### ❌ Wrong: Key with literal \n characters

```
-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjE...
```

### ✅ Right: Key with actual newlines

```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjE...
```

### ❌ Wrong: Key with passphrase

```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key
Enter passphrase: mypassword  # DON'T DO THIS
```

### ✅ Right: Key without passphrase

```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/github_deploy_key
Enter passphrase: [just press Enter]  # Leave empty
```

### ❌ Wrong: Missing BEGIN/END lines

```
b3BlbnNzaC1rZXktdjEAAAAABG5vbmU...
```

### ✅ Right: Complete key with headers

```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmU...
-----END OPENSSH PRIVATE KEY-----
```

---

## 📋 Checklist

Before trying again, verify:

- [ ] Generated new key with NO passphrase
- [ ] Key starts with `-----BEGIN OPENSSH PRIVATE KEY-----`
- [ ] Key ends with `-----END OPENSSH PRIVATE KEY-----`
- [ ] Copied ENTIRE key (all lines, including BEGIN/END)
- [ ] Pasted into GitHub Secret with no extra spaces
- [ ] Added PUBLIC key to server's `~/.ssh/authorized_keys`
- [ ] Set permissions: `chmod 600 ~/.ssh/authorized_keys`
- [ ] Tested key locally with `ssh -i ~/.ssh/github_deploy_key user@server`
- [ ] Verified key works before trying GitHub Actions

---

## 🧪 Debug: View What GitHub Received

The workflow now prints the first and last line of the key. Check the Actions log:

```
First line of key:
-----BEGIN OPENSSH PRIVATE KEY-----
Last line of key:
-----END OPENSSH PRIVATE KEY-----
```

If you see anything else, the key was pasted wrong.

---

## 🆘 Still Not Working?

### Check Server Logs

SSH to your server and check:

```bash
# View auth logs
sudo tail -f /var/log/auth.log
# or
sudo tail -f /var/log/secure

# While watching, trigger the deployment
# You'll see exactly why SSH is rejecting the key
```

### Try Different User

Sometimes your username is different than you think:

```bash
# Check what users exist
cat /etc/passwd | grep home

# Try SSH with different username
ssh differentuser@psd1.net
```

### Check Server's SSH Config

```bash
# Make sure server allows pubkey auth
grep PubkeyAuthentication /etc/ssh/sshd_config
# Should show: PubkeyAuthentication yes
```

---

## 💡 Pro Tip: Use SSH Agent Action Instead

If you keep having issues, you can use a GitHub Action that handles SSH keys:

```yaml
- name: Setup SSH
  uses: webfactory/ssh-agent@v0.9.0
  with:
    ssh-private-key: ${{ secrets.SSH_PRIVATE_KEY }}
```

This handles all the key formatting automatically!

Let me know if you want me to switch to this method.

---

## ✅ Success Indicators

You'll know it's working when you see in the Actions log:

```
Testing SSH connection...
SSH connection successful!
```

Then rsync will work and files will deploy!

---

Last Updated: 2026-01-17

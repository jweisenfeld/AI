# Two Futures for Games — setup guide

A single Google Apps Script web app that shows students paired passages, collects Claim–Evidence–Reasoning responses, grades them with Claude using your API key, logs everything to a Google Sheet, and emails feedback to the student and to you.

Your API key never leaves Google's servers. Students only see the web page.

Time to set up: about 20 minutes, plus pasting the excerpts.

## 1. Create the project

1. Go to script.google.com while signed in to your district Google account.
2. New project. Name it "Two Futures for Games".
3. You'll see one file, `Code.gs`. Delete its contents and paste in `Code.gs` from this folder.
4. Click **+** next to Files → **Script**. Name it `Passages`. Paste in `Passages.gs`.
5. Click **+** → **HTML**. Name it `Index`. Paste in `Index.html`.
6. Save (Ctrl/Cmd+S).

## 2. Add your API key

1. Left sidebar → **Project Settings** (gear icon).
2. Scroll to **Script Properties** → **Add script property**.
3. Property: `ANTHROPIC_API_KEY`  Value: your key from console.anthropic.com.
4. Save.

The teacher email defaults to `jweisenfeld@psd1.org` in `CONFIG` at the top of `Code.gs`. To send teacher copies elsewhere, add another property `TEACHER_EMAIL`.

## 3. Test

1. Back in the editor, choose `testSetup` from the function dropdown and click **Run**.
2. Google will ask you to authorize the script (it needs to send email, create a Sheet, and reach an external URL). Approve it. If you see "Google hasn't verified this app", click Advanced → Go to Two Futures for Games (unsafe). That warning appears for every personal script.
3. Check **Execution log**: you want `API status: 200`. You'll also get a test email.
4. Optional: run `testFullRun` to grade a fake submission end to end. Both emails go to you.

## 4. Paste the excerpts

Open `Passages.gs`. Each of the 8 rounds has two `text: ''` fields. The `hint` above each one tells you which section of the source to pull from and what the excerpt should show. Paste the passage between the quotes. Aim for 80–200 words each. Use `\n` for a paragraph break and `\"` for any double quotes inside the passage.

Save, then run `testSetup` again; the log tells you if any round is still empty. Students see a yellow "not added yet" note for any missing passage, so a half-finished setup won't crash.

## 5. Deploy

1. **Deploy** (top right) → **New deployment**.
2. Type: **Web app**.
3. Execute as: **Me**. (Emails and the Sheet come from your account.)
4. Who has access: **Anyone within Pasco School District** if your students sign in with district accounts — this also confirms who they are. Otherwise **Anyone**.
5. Deploy → copy the Web app URL. That's the link you post in Classroom or Minga.

Whenever you edit `Passages.gs` later: Deploy → **Manage deployments** → pencil → Version: **New version** → Deploy. The URL stays the same.

## 6. What students experience

- They enter name and email, read eight side-by-side pairs, and write Claim / Evidence / Reasoning for each. Answers autosave in their browser, so they can leave and come back on the same device.
- "Send my responses" takes 20–40 seconds. They see their score and feedback on the page and get the same by email.
- You get an email per submission with the score, feedback, the full responses, and a link to the Sheet. The Sheet has one row per submission with every response, so you can sort, filter, or import to your gradebook.

## Scoring (20 points)

| Part | Points | How it's scored |
|---|---|---|
| Engagement | 8 | Computed, not judged: 1 point per round where Claim, Evidence, and Reasoning each have at least 15 characters. |
| Claims | 4 | Claude: clear, arguable, answers the prompt. |
| Evidence | 4 | Claude: accurate to the passages, attributed to the right author, uses both authors when asked. |
| Reasoning | 4 | Claude: explains why the evidence supports the claim; engages the tension between the two authors. |

Change the weights in `CONFIG.POINTS` if you'd like. The grading rubric is in `gradeWithClaude()` in plain English; edit it the way you'd edit a rubric.

## Cost

Each submission is one API call of roughly 6–9k input tokens and under 2k output. With `claude-sonnet-5` that's a few cents per student. Switch `CONFIG.MODEL` to `claude-haiku-4-5-20251001` for roughly a fifth of the cost; check current pricing at platform.claude.com/docs/en/about-claude/pricing.

## Things to know

- **Student data.** Student names, emails, and written responses are sent to Anthropic's API for grading. Check your district's policy on third-party AI tools before assigning. The Sheet and emails stay inside your Google account.
- **Lock it down.** Set `ALLOWED_EMAIL_DOMAIN: 'psd1.org'` in `CONFIG` to reject non-district addresses.
- **Spoofing.** A student could type a classmate's name. Using "Anyone within Pasco School District" access is the simplest guard, since they must be signed in.
- **Quotas.** Consumer Gmail accounts can send about 100 emails/day from Apps Script; Workspace accounts get 1,500. Each submission sends two.
- **Resubmits.** Nothing stops a student from sending twice; each goes on a new row, so you'll see both.
- **Copyright.** The excerpts are yours to select under your own fair-use judgment as an educator. This package deliberately ships without the article text.

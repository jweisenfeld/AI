# coach6/ — CLAUDE.md

## Purpose
PowerPoint presentation grader for the Engineering Design Process project.
Students upload (or share via OneDrive link) their .pptx presentation.
Gemini 2.5 Pro evaluates it against a rubric and emails feedback to the student
(CC: teacher). No chat interface — one-shot upload → results.

Deployed at psd1.net (Bluehost cPanel hosting).
Students are 9th-grade Engineering students at Orion High School, Pasco WA.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Student-facing UI: login, OneDrive URL paste, file upload, results display |
| `api-proxy.php` | Backend: login, file download/upload, Gemini grading (2 passes), Gmail SMTP email |
| `rubric-v1.md` | Rubric in Markdown format (versioned). Embedded in Gemini system prompt. |
| `student_submissions/` | Saved .pptx files: `{studentID}_{timestamp}.pptx` (blocked from web access) |
| `grader.log` | Per-request log: submission, grading scores, email success/fail |

## Secrets (in `../.secrets/` relative to document root)
| File | Contents |
|------|----------|
| `amentum_geminikey.php` | `$GEMINI_API_KEY` — Google Gemini API key |
| `student_roster.csv` | Roster CSV; col 2=studentID, col 6=password, col 8=email, col 9=name |
| `smtp_credentials.php` | Gmail SMTP: `$SMTP_HOST`, `$SMTP_PORT`, `$SMTP_USER`, `$SMTP_PASS`, `$SMTP_FROM`, `$SMTP_FROM_NAME`, `$TEACHER_CC` |

## SMTP Credentials File Format (`smtp_credentials.php`)
```php
<?php
$SMTP_HOST      = 'smtp.gmail.com';
$SMTP_PORT      = 587;
$SMTP_USER      = 'jweisenfeld@psd1.net';
$SMTP_PASS      = 'your-16-char-gmail-app-password'; // Gmail App Password (not regular password)
$SMTP_FROM      = 'jweisenfeld@psd1.net';
$SMTP_FROM_NAME = 'Mr. Weisenfeld - Engineering Coach';
$TEACHER_CC     = 'jweisenfeld@psd1.org';
```

## Gmail App Password Setup (required for SMTP)
1. Go to myaccount.google.com → Security → 2-Step Verification (must be ON)
2. Go to myaccount.google.com/apppasswords
3. Create app named "coach6"
4. Copy the 16-character password (no spaces) into smtp_credentials.php

## Grading Flow
1. Student logs in (same roster as coach5)
2. Pastes OneDrive "Anyone with the link" URL or uploads .pptx directly
3. Server downloads file (appends `&download=1` to SharePoint URL)
   - If response is HTML → restricted link error → shows orionhs.us/anyonewiththelink video
4. Saves .pptx to `student_submissions/{studentID}_{timestamp}.pptx`
5. Uploads .pptx to Gemini Files API
6. **Pass 1**: Gemini 2.5 Pro grades against rubric (JSON response)
   - 5 categories × 4 pts = 20 pts total
   - Answers any TODO: items found in slides
7. **Pass 2**: Gemini 2.5 Pro checks grammar/spelling (JSON response)
   - Score starts at 10, -1 per error found
8. Sends HTML email via Gmail SMTP (student + CC teacher)
9. Returns JSON to browser for display

## Rubric Versioning
- Rubric is in `rubric-v1.md` (Markdown)
- Version string `RUBRIC_VERSION` defined in `api-proxy.php`
- Displayed in email and on-screen results so students know which version was applied
- To update rubric: edit rubric-v1.md, bump to rubric-v2.md, update constant

## TODO Feature
Any text on a slide starting with `TODO:` is treated as a student question.
Gemini answers it in the `todo_answers` array in the grading response.
Displayed prominently in results under "Your TODO Questions — Answered".

## Model
Hard-wired to `gemini-2.5-pro`. No model selector. No token limits on input or output.

## Key Differences from coach5
- No chat interface — single upload → results flow
- No conversation history
- Two Gemini API calls per submission (rubric grading + grammar)
- Accepts .pptx via OneDrive URL or direct file upload
- Saves every submission to disk for longitudinal learning tracking
- Gmail SMTP (not Bluehost mail) for email delivery
- No explicit caching (each submission is unique)

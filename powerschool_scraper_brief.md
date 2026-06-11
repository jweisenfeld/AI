# PowerSchool Grade Scraper — Claude Code Brief

## Goal

Build a Python script (`scrape_grades.py`) that logs into PowerSchool as each student,
checks S2 grades, and writes an `f.html` report to the student's folder for any student
with at least one failing class (below 60%). Students who are passing everything get
skipped silently.

---

## Configuration (top of script)

```python
PARALLELISM = 5          # concurrent browser contexts; adjust as needed
STUDENT_CSV  = "students.csv"
OUTPUT_ROOT  = r"C:\Users\johnw\Documents\GitHub\AI"
POWERSCHOOL  = "https://pschool.psd1.org"
SCHOOL_ID    = "5759"
TERM_START   = "01/27/2026"
TERM_END     = "06/11/2026"
TERM_FG      = "S2"
ATTENDANCE_EMERGENCY_THRESHOLD = 50   # total absences → compact report
```

---

## Student Input CSV

`students.csv` — one row per student:

```
folder,username,password,last_name,first_name
aaron046,arms311,arms311,Armbrust,Aaron
elijah694,garci661,garci661,Garcia,Elijah Just
...
```

`folder` is the subfolder name under `OUTPUT_ROOT` where `f.html` will be written.

---

## Tech Stack

- **playwright** (async) — `pip install playwright && playwright install chromium`
- **asyncio.Semaphore** for concurrency control
- **tqdm** for progress bar
- Retry list written to `failed_students.csv` at the end

---

## Login Flow

```python
URL:  {POWERSCHOOL}/public/home.html

# Inject credentials directly into the form fields and click sign-in:
await page.evaluate("""
    document.getElementById('fieldAccount').value = '{username}';
    document.getElementById('fieldPassword').value = '{password}';
    document.getElementById('btn-enter-sign-in').click();
""")

# Confirm login succeeded by checking the redirected URL contains /guardian/home.html
# If "Invalid Username or Password" appears in page text → log to failed_students.csv

# Log off when done:
await page.goto(f"{POWERSCHOOL}/guardian/home.html?ac=logoff")
```

---

## Grade Overview Scraping

After login, navigate to `/guardian/home.html` and parse the grades table.

For each row in the Attendance By Class table, extract:
- **Expression** (period, e.g. `1(A)`)
- **Course name** (strip ` - ORION` suffix)
- **Teacher name**
- **Room number**
- **S2 grade** — numeric percentage (e.g. `71`, `F 47` → store as `47`)
- **Absences** (integer)
- **Tardies** (integer)

Mark a class as **failing** if S2 grade < 60, **or** if S2 grade string starts with `F`.

Skip non-graded rows: LUNCH, SCHOOL COUNSELOR, ADVISORY, ENHANCEMENT/INTERVENTION.

If **total absences ≥ ATTENDANCE_EMERGENCY_THRESHOLD**, set `attendance_emergency = True`
for that student. In this mode, write a compact HTML report (see template section) and
skip individual score scraping.

---

## Score Detail Scraping (per failing class)

The FRN (section ID) is embedded in the href of the grade link on the overview page.
Extract it from the DOM anchor whose text matches the grade (e.g. `F47`).

Score page URL:
```
{POWERSCHOOL}/guardian/scores.html?frn={FRN}&begdate={TERM_START}&enddate={TERM_END}&fg={TERM_FG}&schoolid={SCHOOL_ID}
```

From the Assignments table, extract per row:
- **due_date** — e.g. `06/10/2026`
- **assignment_name** — strip flag text (collected / late / missing / etc.)
- **raw_score** — e.g. `8.4/10`, `0/4`, `--/4` (unscored), `3.36/4`
- **pct** — numeric percentage if present, else `None`
- **is_zero** — `True` if raw score is `0/N` (not `--/N`)
- **is_unscored** — `True` if raw score is `--/N`
- **weight** — if "weighted x N" appears in the row, store N (Economics uses x80)

Also capture: **last_updated** date from "Assignment Score Or Flag Last Updated: ..."

If the scores page has no assignment rows (empty gradebook), mark class as `no_data = True`.

---

## HTML Report Generation

Write to `{OUTPUT_ROOT}/{folder}/f.html`.

### CSS classes used (must appear in `<style>` block):

```css
.header      { border-bottom: 3px solid #1e40af; }
.header h1   { color: #1e3a8a; }
h3           { color: #b91c1c; }          /* failing class heading */
.fail        { color: #b91c1c; font-weight: 700; }
.pass        { color: #15803d; font-weight: 600; }
.zero        { background: #fef2f2; }     /* row with a zero */
.missing-tag { color: #b91c1c; font-size: 11px; font-weight: 600; }
.intro       { background: #eff6ff; border-left: 4px solid #1e40af; }  /* ≤3 F's, neutral */
.alert       { background: #fef2f2; border-left: 4px solid #b91c1c; }  /* 4+ F's, urgent */
.no-data     { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.engage      { background: #f0fdf4; border-left: 4px solid #15803d; }  /* re-engagement */
.summary-box { background: #fef2f2; border: 1px solid #fecaca; }
.note        { font-size: 12px; color: #6b7280; font-style: italic; }
.footer      { border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; }
```

### Report structure:

```
<div class="header">   Student name, school, term
<div class="intro|alert">   Summary sentence (# failing classes, key context)
<h2> Current Classes — Overview </h2>
<table>  All classes, color-coded pass/fail, absences column

For each failing class:
  <h3>  N. ClassName — F (X%)
  <p>   Teacher | Room | Period | Absences | Tardies
  [one of the three content blocks below]
  <div class="summary-box">  Bullet analysis

<div class="footer">  Generated date, 60% threshold note
```

### Content block selection (per failing class):

**A. Has assignment data** → render assignments table:
```
| Date | Assignment | Score | % |
```
- Rows with `is_zero=True`: apply `class="zero"`, append `<span class="missing-tag">ZERO</span>`
- Rows with `is_unscored=True`: show `—/N` and `No score` in % column; apply `class="zero"` (light highlight)
- Passing scores: apply `class="pass"` to % cell
- Failing scores: apply `class="fail"` to % cell

**B. No assignment data** (`no_data=True`):
```html
<div class="no-data">
  No assignment detail has been published in this gradebook for S2.
  Contact [Teacher] directly to determine what work is outstanding.
</div>
```

**C. Re-engagement pattern** — use `.engage` green box when ALL of these are true:
- Student has recent submissions (within last 4 weeks) scoring ≥ 80%
- Earlier submissions in the same class scored 0%
- Write a note like: "Recent work shows improvement — early zeros are pulling the grade down."

---

## Special Cases

### Economics (Dunn) — ×80 weighting
Every assignment is weighted ×80. Add this note below the class heading:
```html
<p class="note">All assessments weighted ×80. One zero has enormous grade impact.</p>
```

### Attendance Emergency (total absences ≥ 50)
Skip score scraping entirely. Write compact HTML:
- Overview table of all classes
- Alert box: counselor contact recommended, list failing classes with absence counts
- No per-class score tables

### Engineering (Gourley) — unscored items pattern
Many students have 8–10 items from March–June with no score (`--/N`) while only failing
by a small margin. When `last_updated` is more than 2 weeks before today AND the student
has ≥5 unscored items, add a note:
```
Gradebook last updated {date}. {N} items from {earliest}–{latest} have no score — 
contact Mr. Gourley to determine if submitted work is pending grading.
```

### Health Science (Sharpe) — weighted Capstone
SkillsUSA Capstone Project is weighted ×2. Show "(×2 weight)" in the assignment name cell.

---

## Summary Box Logic

Generate bullet points automatically:

1. Count zeros → "X zeros found" (list the assignment names)
2. Count unscored → "Y assignments have no score entered (last updated: DATE)"
3. Completed scores → "When submitted, scores range Z%–Z%" (if ≥2 data points)
4. Attendance note if absences > 5 in that class
5. Re-engagement note if applicable

---

## Output & Error Handling

**On completion:**
- Print summary: `N students processed, M had F grades (reports written), K skipped (all passing), J failed (login error)`
- Write `failed_students.csv` with columns: `folder, username, error_reason`
- Write `summary.csv` with: `folder, name, total_absences, failing_classes, report_written`

**Retry flow:**
- `scrape_grades.py --retry` reads `failed_students.csv` and reprocesses only those students

**Progress bar:** tqdm over the student list, updated as each student completes.

---

## Known Login Credential Format

Passwords are typically the same as the username (e.g. username `arms311`, password `arms311`).
Some students have a different password — the CSV accommodates this with separate columns.

---

## Example Invocation

```bash
# Full run
python scrape_grades.py

# Adjust concurrency
# Edit PARALLELISM = 10 at top of file

# Retry failed students only
python scrape_grades.py --retry

# Single student (for testing)
python scrape_grades.py --only aaron046
```

---

## File to Create

`C:\Users\johnw\Documents\GitHub\AI\scrape_grades.py`

Ask Claude Code:
> "Build this exactly per the brief. Use Playwright async API with asyncio.Semaphore for
> concurrency. Generate f.html using Python string templating (no external template engine).
> Make this parallelizable with a configurable concurrency limit via the PARALLELISM variable
> at the top."

# Prompt for the Q2 co-enrollment conversation

**Attach to the new chat before sending:**

1. The Q2 Student Schedule Matrix report, saved as HTML (PowerSchool › Reports › Student Schedule Matrix — same export Mrs. Serna sent for Q1, e.g. `9th_Qrt2__Schedule_Matrix_Report_<date>.html`).
2. `coenrollment_map.py` (the generator from the Q1 work).
3. Optional but recommended: the Q1 HTML (`9th_Qrt1__Schedule_Matrix_Report_9_9_26.html`) so Claude can do the Q1→Q2 comparison.

Then paste everything below the line.

---

I'm John Weisenfeld, 9th-grade Physics and Computer Gaming & Design teacher at Orion High School (PSD1). Attached is the PowerSchool Student Schedule Matrix report for every freshman's **Q2** schedule, saved as HTML by our counselor, Mrs. Serna. Q2 has not started yet — this is the draft she built, and she and I will be going through it together. So treat this as a schedule audit for the counselor as much as a co-enrollment analysis for teachers.

We did this for Q1 already (you can search our past chats for "Q1 coenrollment map" if you want to see how it went). Reproduce it for Q2 first, then extend it.

## 1. Run the attached script first

`coenrollment_map.py` parses this exact report format and builds the pages. Read its docstring, then run:

```
python3 coenrollment_map.py "<the Q2 html>" --pulled "<date the report was pulled>" --tidy
```

It auto-detects the term (Q2), prints a course-classification table (Math = OMT course codes; ELA = OEN/OSD codes with "English" in the name; Podcasting is an ELA elective and is *not* counted as an ELA class; "INC" sections are inclusion support sections), and writes one page per non-Math/ELA teacher as `q2<lastname>.html`.

- Check the classification table against the courses actually in the file. If Q2 has new courses or codes, tell me what landed where and fix anything wrong (the `classify()` function) before building.
- Present at minimum `q2weisenfeld`, `q2arambul`, `q2fraser`, `q2riley`, `q2cochran`, plus a page for any new teacher who appears. Don't assume Q1's teachers, sections, or periods carried over.
- If the script fails because the report format changed, fix the script rather than rebuilding by hand, and tell me what changed.
- The `--tidy` CSVs contain student names and IDs. Keep them in the working directory, never in outputs.

If the script is **not** attached, rebuild from this spec: the report has one `<table id="schedMatrixTable">` per student, preceded by "Student Name:", "Student ID:", "Homeroom:", "Grade:", and "Year of Graduation:" text. Course cells are `td.matrix_N`; when a student is double-booked the cell is `td.section-conflict` holding several `div.matrix_N` blocks separated by `<hr>`. Each course is five `<p>` elements: `.sched-course-name`, `.sched-section-number` (CODE . SECTION), `.sched-teacher-name`, `.sched-room`, `.sched-term` (expression and term, e.g. "2(A) Q2"). Year-long items (Advisory, School Counselor, SPED Case Mgmt) carry the school-year term (e.g. 26-27); Lunch is LCH100. Keep only the quarter's academic sections. Each page is one self-contained HTML file with no external dependencies: a three-column alluvial diagram (your sections → their Math class → their ELA class) with ribbons colored by the origin section, hatched "No Math class / No ELA class this quarter" nodes, hover-to-highlight and click-to-pin; a "Who to sit down with" list of the Math and ELA teachers ranked by unique shared students with the specific period-to-period clusters spelled out in sentences; the whole 9th-grade team's shared-student matrix with the focus teacher's row and column outlined; and counselor notes.

## 2. Tell me what you notice

Same lens as Q1:

- The bell schedule and term model, and anything that changed from Q1.
- How many freshmen have a Math class, an ELA class, both, neither — grade-wide and in my sections.
- Which teachers are the single point of failure for a core course, and who the hub of the team matrix is.
- Data-quality flags: blank matrices; every PowerSchool section-conflict cell, grouped by course pair and period, with your read on which are intentional co-teaching and which are real conflicts; students missing lunch, advisory, or a counselor; graduation-year anomalies; section sizes that look unbalanced.

## 3. Audit questions for Mrs. Serna

This is the expansion. Give counts, and let me pull names from PowerSchool myself. If I explicitly ask for a name list to hand to Mrs. Serna, put it in a separate file clearly marked confidential — never on the teacher pages.

- Section sizes for every Q2 section; flag anything over 30 or under 10.
- Students with 2+ courses in one period, by course pair. Which pairs make sense as inclusion co-teaching (an INC section sharing a period with the matching general-ed section) and which don't.
- Students with no Math or no ELA in Q2. If the Q1 file is attached: students with no Math, or no ELA, in **both** Q1 and Q2 — a full semester without it.
- Inclusion coverage: how many SPED-case-managed students (SED999) sit in each general-ed section, and whether each INC section actually shares a period with the class it supports.
- Advisory: anything odd about advisory rosters versus the academic schedule.
- Anything else you would flag if you were the counselor's second pair of eyes on a draft.

## 4. Q1 → Q2 comparison (if the Q1 file is attached)

For each colleague's page, what changed in their partner rankings and biggest clusters; how the team matrix shifted; which Q1 flags got fixed; and how many students changed Math/ELA status between quarters.

## 5. Be ready for what-ifs

Mrs. Serna may want to move a section or a handful of students. Keep the parsed data loaded so you can answer "if section X moved to period Y" or "if these N students swapped into Eaton's period 2" with updated counts, and regenerate any page on request.

## House rules

Capitalize Math; call the subject ELA (course names stay as PowerSchool prints them); pages are named `q2<lastname>`; same design as Q1; no student names or IDs on any page.

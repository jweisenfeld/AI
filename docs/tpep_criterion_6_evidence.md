# TPEP Criterion 6 Evidence Review — Student Data, Assessment, Progress Monitoring, Intervention, Reteaching, and Instructional Adjustment

## Scope and methodology

This review looked at the current Git branch's changelists/commits dated **August 1, 2025 through May 18, 2026** and the web-based physics pages currently in the repository. The current checkout is a shallow Git repository with only one local ref, `work`, so the available branch history contains **102 commits in that date range**, all dated **April 28, 2026 through May 17, 2026**; no August 2025–March 2026 commits are present locally to inspect. The pages themselves contain Semester 2 student progress histories from **January 18, 2026 through May 16, 2026**.

Commands used for the review included:

- `git log --since='2025-08-01' --date=iso --pretty=format:'%h%x09%ad%x09%s'`
- `git log --since=2025-08-01 --name-only --pretty=format:...`
- `git log --all --reverse --date=short --pretty=format:'%h %ad %s'`
- `git for-each-ref --format='%(refname:short)'`
- `git rev-parse --is-shallow-repository`
- Python parsing of generated `const pageData = {...};` blocks in student grade pages
- `rg` searches for evidence terms such as `PositivePhysics`, `assessment`, `score`, `progress`, `reset`, and unit/resource names


## Why the report cannot see more branch history right now

The limiting factor is the local Git data, not the evidence-analysis method. This checkout reports `true` for `git rev-parse --is-shallow-repository`, and `git for-each-ref --format='%(refname:short)'` shows only the local `work` ref. Running `git log --all --reverse --date=short --pretty=format:'%h %ad %s'` shows the oldest locally available commit as **April 28, 2026**. Because earlier commits are not present in `.git`, this report cannot reconstruct August 2025–March 2026 changelists from the current local checkout.

To produce a more extensive historical report, use one of these sources:

1. A full, unshallow clone of the repository that includes all refs/branches and tags.
2. The remote Git host's complete history, if available.
3. Any CI/deployment logs, Perforce depot/changelist exports, ZIP snapshots, or backups that predate April 28, 2026.
4. Periodic evidence reports saved in the repository going forward.

If a full remote is available, the next review should first run `git fetch --unshallow --all --tags` or reclone without `--depth`, then rerun the same evidence extraction. If no full remote/backups exist, the current report can still use page-internal progress histories, but it cannot cite missing Git changelists that were never present locally.

## Recommended reporting cadence

Yes: run and commit this report regularly so relevant changelists are captured while they are still available and easy to summarize.

Recommended cadence:

- **Monthly:** best for TPEP evidence. It captures frequent progress-monitoring cycles, assessment updates, resets/corrections, resource additions, and intervention patterns without creating an end-of-year reconstruction problem.
- **Quarterly:** acceptable as a minimum. It is better than waiting until the end of the year, but it may blur daily/weekly intervention patterns.
- **End of each unit or grading period:** ideal supplement when a major assessment, reteaching cycle, or intervention push occurs.

Recommended monthly artifact contents:

- Commit range and dates reviewed.
- Number of student dashboard/calendar/grade files updated.
- Unit assessments active during the month.
- Aggregate counts of students below/near/meeting proficiency, without student names.
- Examples of instructional adjustments, such as added simulations, calculators, reteaching resources, reset links, or correction workflows.
- A short reflection connecting the data to interventions and reteaching decisions.

## Executive conclusion

The changelists and generated pages provide strong evidence that Criterion 6 was met and, in several areas, exceeded. The evidence shows a repeated cycle of collecting student learning data, displaying assessment results, monitoring progress by student and by unit, identifying students needing intervention, and giving students routes to improve through corrections, reset requests, and targeted instructional resources. The repository directly documents the data systems and instructional artifacts; the teacher's stated one-on-one conferences are best treated as corroborating professional reflection because the repo supports the identification/intervention workflow but does not directly record private conference notes.

## Quantitative evidence from the repository

| Evidence category | Finding |
| --- | --- |
| Unique student records parsed from grade pages | **143** unique student IDs |
| Student grade/assessment pages | **427** generated grade-page files containing `pageData` blocks |
| Calendar/progress files | **416** `.calendar.html` files, plus calendar images and embedded calendar dashboards |
| Progress history entries | **14,078** dated student progress records |
| Progress-monitoring date span inside pages | **101 distinct dates**, from **2026-01-18** through **2026-05-16** |
| Average progress snapshots per student | **98.45** dated records per unique student |
| Current-risk triage from parsed official grades | **58 of 143** students below 70%; **4 of 143** below 60% |
| Student home pages with embedded dashboards | **143** student `index.html` pages include dashboard embeds |
| Student home pages with assessment-reset pathway | **143** student `index.html` pages include “Request Assessment Reset” |
| Relevant file-change volume since 2025-08-01 | **22,577** calendar/progress file-change entries, **10,048** grade/assessment file-change entries, **704** student home/resource page file-change entries |
| Relevant update frequency since 2025-08-01 | Calendar/progress pages were touched in **78 commits**; grade/assessment pages were touched in **35 commits**; student home/resource pages were touched in **44 commits** |

## Criterion 6 evidence map

### 1. Student data collection and use

The generated student pages store individualized data, not merely generic course content. A representative grade page includes a JSON-like `pageData` object with student identifier, assessment milestones, dated progress history, original and current assessment scores, missing flags, official percent, lesson average, grade thresholds, and grade weighting. This demonstrates systematic collection and use of student-level data.

A representative calendar page also shows student-specific course status: the page is generated for a specific student, lists PositivePhysics Semester 2 units, shows lesson completion percentages, shows last-activity timestamps, and displays assessment scores by unit. These are direct artifacts of student data being transformed into actionable instructional displays.

### 2. Assessment results

Assessment evidence is explicit. The student grade pages list unit assessment milestones such as Unit 6, Unit 7, Unit 19, Unit 22, Unit 25, Unit 26, and Unit 29 assessments. The same pages maintain arrays of original scores and current scores, a formal grade model, and official-percent outputs.

The calendar pages show assessment due dates, assessment scores, original attempt scores, correction scores, and finalized status. This is strong evidence that student achievement was measured using unit assessment outcomes and then made visible for students and the teacher.

### 3. Progress monitoring

Progress monitoring is one of the strongest evidence areas. Parsed grade pages contain **14,078** dated progress records across **101** distinct dates. This supports the teacher's summary that progress was monitored regularly and often daily. The pages track percent progress across time, not just a single end-of-unit score.

The Git history reinforces that monitoring was an ongoing workflow. Since April 28, 2026, there were repeated “Calendar File Updates” and “Auto-update” commits, often multiple times per day, affecting hundreds of student progress and grade files at a time. That pattern is consistent with frequent review and refresh of student progress data.

### 4. Identification of students needing intervention

The repository provides multiple mechanisms for identifying students needing intervention:

- Calendar pages color-code lesson completion, with visible ranges for “Below 50% — Not started or minimal progress” and “50–69% — In progress.”
- Student data include missing flags, lesson averages, official percentages, and dated progress histories.
- Parsed current-grade data show **58 of 143** students below 70% and **4 of 143** below 60%, giving a concrete triage list for daily intervention.
- Calendar pages display last-activity timestamps, helping distinguish students who are stuck, inactive, or behind pacing.

This directly supports the teacher's statement that progress monitoring involved keeping track of who was passing and who was not.

### 5. Intervention and opportunities to improve proficiency

The student home pages include a “Request Assessment Reset” pathway and state that students must complete all lessons for the unit before an assessment reset will be given. This is evidence of an intervention structure tied to evidence of readiness: students first complete missing/incomplete lessons, then can attempt to demonstrate proficiency again.

The calendar pages also show original and correction scores. This documents a correction/reassessment process rather than one-shot grading. The workflow supports intervention by showing which students need corrections, which have completed corrections, and whether finalization has occurred.

The repository does **not** directly record confidential one-on-one conference notes. Therefore, the one-on-one intervention evidence should be presented as teacher reflection supported by the data system: the pages identify who needs help, what unit/lesson/assessment is the issue, and what completion/correction step is needed next.

### 6. Reteaching and instructional adjustment

The changelists show instructional adjustment through resource additions and unit-specific supports. Student pages include course resources and embedded dashboards, and relevant commits added or revised physics resources such as sound, Kundt's tube, speed of sound, and wave interference. Representative student home pages include embedded dashboards for “Physics Grades” and “Assignment Calendar,” a reset pathway, and supplemental resources including simulations and calculators.

This supports a Criterion 6 narrative that assessment/progress data was used to adjust instruction: when students needed additional pathways, resources were embedded in their pages; when students needed to improve assessment outcomes, corrections and resets were available; when the class moved into units requiring additional visualization, resources were added to student pages.

## Recommended evidence statements for a TPEP artifact

The following language is supported by repository evidence and can be adapted for a self-evaluation or artifact reflection:

> I monitored student progress through individualized web dashboards that pulled PositivePhysics lesson progress, unit assessment status, current grade data, and assessment correction data into student-specific pages. These pages were refreshed frequently, and the repository contains thousands of progress and assessment file updates across the spring semester.

> I used unit assessment results and lesson-completion data to identify students who were passing, students who were near proficiency, and students who required immediate intervention. The dashboards showed official percentages, lesson averages, missing flags, last activity, original assessment scores, correction scores, and finalized status.

> When students were not demonstrating proficiency, I used the data to direct intervention: students completed unfinished lessons, requested assessment resets when ready, completed corrections, and received targeted support based on the unit and skill where the data showed a gap. One-on-one conferences are not directly recorded in the repo, but the dashboard artifacts show the data used to select students and guide those conferences.

> I adjusted instruction and support by embedding relevant simulations, calculators, unit resources, grade dashboards, assignment calendars, correction pathways, and assessment-reset pathways in student pages. This created a continuous cycle of assessment, feedback, intervention, and reassessment.

## Raw repository examples used as evidence

Representative files reviewed:

- `julian823/45550.calendar.html` — PositivePhysics schedule, lesson completion, last activity, assessment due dates, original/correction scores, finalized status, and progress legend.
- `julian823/45550.html` — grade model, milestones, progress history, original/current scores, lesson average, official percent, and what-if grade calculation.
- `julian823/index.html` — links and embedded dashboards for Physics Grades, Assignment Calendar, Request Assessment Reset, and supplemental unit resources.
- Student files across 143 unique student IDs — aggregate evidence of repeated individualized dashboards.
- Git commits dated April 28, 2026 through May 17, 2026 — aggregate evidence of repeated calendar, grade, and resource updates on the current branch.

## Limitations to note

- The current checkout is shallow and contains only the `work` ref, so it does not contain commits dated August 2025 through March 2026 even though the requested review period starts in August 2025. The available local branch history starts April 28, 2026.
- The repository contains the data artifacts and update history, but it does not directly document private student-teacher conferences. Those should be described as professional reflection connected to the dashboard evidence rather than as a direct repository artifact.
- Student-identifiable files exist in the repository. Any TPEP submission should use aggregate data, screenshots with names/emails obscured, or de-identified examples.

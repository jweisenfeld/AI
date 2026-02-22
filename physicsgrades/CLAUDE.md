# physicsgrades/ — CLAUDE.md

## Purpose
Contains per-student physics grade pages and assignment calendars for all 158 students.
These HTML files are served at `psd1.net/{studentname}/{studentID}.html`.

## File Naming Pattern
- `{studentID}.html` — grade summary page for a student (e.g., `33272.html`)
- `{studentID}.calendar.html` — assignment calendar for the same student

## How These Files Are Generated
Files are generated externally (by the gradebook/grade export system) and synced
here. The `sync_student_pages.py` script in the repo root copies these files to
individual student subfolders.

## physicsgrades-s1/
Archive of Semester 1 grade pages. Current semester uses `physicsgrades/`.

## Notes
- Do not manually edit individual student HTML files — they are overwritten on sync
- To update grades, re-export from the grade source and re-run the sync script
- ~316 files (158 students × 2 files each)

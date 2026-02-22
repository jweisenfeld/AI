# birthdays/ — CLAUDE.md

## Purpose
Student birthday database used for personalized birthday email outreach.

## Key Files
| File | Description |
|------|-------------|
| `master.csv` | Master birthday database (~103KB) — all students with birthdays |

## Usage
The `send-birthday-emails.py` script in the Misc repo reads from this database
(or from `All-Weisenfeld-Students/`) to find students with upcoming birthdays
and sends them personalized birthday emails.

## Data Format
CSV with columns for student name, ID, email, and birthday date.

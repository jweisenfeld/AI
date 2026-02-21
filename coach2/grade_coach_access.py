"""
grade_coach_access.py
---------------------
Checks which students accessed the Engineering Coach by looking for their
log files on the server, then writes a CSV ready for paste-to-gradebook.py.

HOW IT WORKS
  - Student log files live at: {BASE_URL}/student_logs/{student_id}.txt
  - If the file exists (HTTP 200), student gets POINTS_FULL (they did it).
  - If the file is missing (HTTP 404), student gets POINTS_ZERO (they didn't).
  - Output CSV has the same columns as the input, with Score filled in.

USAGE
  python grade_coach_access.py
  python grade_coach_access.py --base-url https://yoursite.com/gemini2 --csv s2p9PST.csv
  python grade_coach_access.py --base-url https://yoursite.com/gemini2 --points 10

Then run paste-to-gradebook.py with the output CSV:
  python paste-to-gradebook.py --f coach_accessed_s2p9PST.csv --paste-mode

RERUNNING
  Safe to rerun anytime — overwrites the output CSV with updated results.
  Useful as late students trickle in.
"""

import argparse
import csv
import sys
import time
from pathlib import Path

import requests

# ── Defaults ────────────────────────────────────────────────────────────────
DEFAULT_BASE_URL   = "https://psd1.net/gemini2"
DEFAULT_INPUT_CSV  = Path(__file__).parent.parent.parent / "Misc" / "s2p9PST.csv"
DEFAULT_OUTPUT_CSV = Path(__file__).parent.parent.parent / "Misc" / "coach_accessed_s2p9PST.csv"
POINTS_FULL        = 10
POINTS_ZERO        = 0
REQUEST_TIMEOUT    = 8   # seconds
RATE_LIMIT_SLEEP   = 0.1 # seconds between requests (be polite to your own server)
BROWSER_HEADERS    = {   # Bluehost WAF requires a browser-like User-Agent
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/plain,text/html,*/*;q=0.8",
}


def check_log_exists(base_url: str, student_id: str, timeout: int) -> bool:
    """Return True if student's log file exists on the server."""
    url = f"{base_url.rstrip('/')}/student_logs/{student_id}.txt"
    try:
        # Bluehost WAF requires a browser-like User-Agent; HEAD not always supported
        r = requests.get(url, timeout=timeout, stream=True, headers=BROWSER_HEADERS)
        r.close()
        return r.status_code == 200
    except requests.RequestException as e:
        print(f"  WARNING: network error for {student_id}: {e}")
        return False


def main():
    parser = argparse.ArgumentParser(
        description="Grade Engineering Coach access: 10 pts if log exists, 0 if not."
    )
    parser.add_argument(
        "--base-url", default=DEFAULT_BASE_URL,
        help=f"Base URL of the gemini2 deployment (default: {DEFAULT_BASE_URL})"
    )
    parser.add_argument(
        "--csv", default=str(DEFAULT_INPUT_CSV),
        help=f"Input class CSV (default: {DEFAULT_INPUT_CSV.name})"
    )
    parser.add_argument(
        "--out", default=str(DEFAULT_OUTPUT_CSV),
        help=f"Output graded CSV (default: {DEFAULT_OUTPUT_CSV.name})"
    )
    parser.add_argument(
        "--points", type=int, default=POINTS_FULL,
        help=f"Points for completing the assignment (default: {POINTS_FULL})"
    )
    parser.add_argument(
        "--timeout", type=int, default=REQUEST_TIMEOUT,
        help=f"HTTP request timeout in seconds (default: {REQUEST_TIMEOUT})"
    )
    args = parser.parse_args()

    input_path  = Path(args.csv)
    output_path = Path(args.out)

    if not input_path.exists():
        print(f"ERROR: Input CSV not found: {input_path}")
        sys.exit(1)

    # ── Load students ────────────────────────────────────────────────────────
    with open(input_path, encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fieldnames = reader.fieldnames or []
        rows = list(reader)

    if "Student Num" not in fieldnames:
        print(f"ERROR: Expected 'Student Num' column in {input_path.name}")
        print(f"       Found columns: {fieldnames}")
        sys.exit(1)

    if "Score" not in fieldnames:
        fieldnames = list(fieldnames) + ["Score"]

    print(f"Checking {len(rows)} students against {args.base_url}")
    print(f"  Scoring: {args.points} pts if accessed, {POINTS_ZERO} pts if not")
    print("-" * 60)

    accessed   = []
    not_accessed = []

    for row in rows:
        student_id   = str(row.get("Student Num", "")).strip()
        student_name = str(row.get("Student Name", "")).strip()

        if not student_id:
            print(f"  SKIP  (no ID): {student_name}")
            row["Score"] = ""
            continue

        found = check_log_exists(args.base_url, student_id, args.timeout)
        if found:
            row["Score"] = str(args.points)
            accessed.append(student_name or student_id)
            print(f"  FOUND  {student_id:>8}  {student_name}")
        else:
            row["Score"] = str(POINTS_ZERO)
            not_accessed.append(student_name or student_id)
            print(f"  MISSING {student_id:>8}  {student_name}")

        time.sleep(RATE_LIMIT_SLEEP)

    # ── Write output CSV ─────────────────────────────────────────────────────
    with open(output_path, "w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    # ── Summary ──────────────────────────────────────────────────────────────
    total = len(rows)
    print("-" * 60)
    print(f"\nRESULTS: {len(accessed)}/{total} students accessed the coach")
    print(f"  Completed ({len(accessed)}): {', '.join(accessed[:5])}" +
          (f" ... +{len(accessed)-5} more" if len(accessed) > 5 else ""))
    if not_accessed:
        print(f"\n  NOT done ({len(not_accessed)}):")
        for name in not_accessed:
            print(f"    {name}")

    print(f"\nOutput written to: {output_path}")
    print(f"\nNext step:")
    print(f"  python paste-to-gradebook.py --f \"{output_path}\" --paste-mode")


if __name__ == "__main__":
    main()

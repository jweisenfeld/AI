"""
Sync student grade pages from physicsgrades/ to individual student folders.

For each student in the CSV:
1. Ensures their folder exists (AI/{{Username}}/)
2. Updates index.html with links to their grade pages
3. Copies {{Id}}.html and {{Id}}.calendar.html from physicsgrades/

Run from the AI folder:
    python sync_student_pages.py
"""

import csv
import shutil
from pathlib import Path

# Paths
SCRIPT_DIR = Path(__file__).parent
MISC_DIR = SCRIPT_DIR.parent / "Misc"
CSV_PATH = MISC_DIR / "25-26-S2-Passwords-Combined.csv"
PHYSICSGRADES_DIR = SCRIPT_DIR / "physicsgrades"

def generate_index_html(nickname: str, student_id: str) -> str:
    """Generate the index.html content for a student."""
    return f"""<!DOCTYPE html>
<html>
<head>
    <title>{nickname}'s Physics Page</title>
    <style>
        body {{
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }}
        h1 {{
            color: #333;
        }}
        ul {{
            list-style-type: none;
            padding: 0;
        }}
        li {{
            margin: 15px 0;
        }}
        a {{
            color: #0066cc;
            text-decoration: none;
            font-size: 1.2em;
        }}
        a:hover {{
            text-decoration: underline;
        }}
    </style>
</head>
<body>
    <h1>Hi {nickname}!</h1>
    <ul>
        <li><a href="{student_id}.html">Physics Grades</a></li>
        <li><a href="{student_id}.calendar.html">Assignment Calendar</a></li>
    </ul>
</body>
</html>
"""

def sync_student(username: str, student_id: str, nickname: str) -> dict:
    """
    Sync files for a single student.
    Returns a dict with status info.
    """
    result = {
        "username": username,
        "id": student_id,
        "index_updated": False,
        "grades_copied": False,
        "calendar_copied": False,
        "errors": []
    }

    student_dir = SCRIPT_DIR / username

    # Create folder if needed
    student_dir.mkdir(exist_ok=True)

    # Update index.html
    index_path = student_dir / "index.html"
    new_content = generate_index_html(nickname, student_id)

    # Check if update needed
    if index_path.exists():
        existing = index_path.read_text(encoding='utf-8')
        if existing != new_content:
            index_path.write_text(new_content, encoding='utf-8')
            result["index_updated"] = True
    else:
        index_path.write_text(new_content, encoding='utf-8')
        result["index_updated"] = True

    # Copy grade files
    grades_src = PHYSICSGRADES_DIR / f"{student_id}.html"
    grades_dst = student_dir / f"{student_id}.html"

    if grades_src.exists():
        shutil.copy2(grades_src, grades_dst)
        result["grades_copied"] = True
    else:
        result["errors"].append(f"Missing: {grades_src.name}")

    # Copy calendar files
    calendar_src = PHYSICSGRADES_DIR / f"{student_id}.calendar.html"
    calendar_dst = student_dir / f"{student_id}.calendar.html"

    if calendar_src.exists():
        shutil.copy2(calendar_src, calendar_dst)
        result["calendar_copied"] = True
    else:
        result["errors"].append(f"Missing: {calendar_src.name}")

    return result

def main():
    print(f"Reading students from: {CSV_PATH}")
    print(f"Source files from: {PHYSICSGRADES_DIR}")
    print("-" * 50)

    if not CSV_PATH.exists():
        print(f"ERROR: CSV not found: {CSV_PATH}")
        return

    if not PHYSICSGRADES_DIR.exists():
        print(f"ERROR: physicsgrades folder not found: {PHYSICSGRADES_DIR}")
        return

    students_processed = 0
    indexes_updated = 0
    files_copied = 0
    errors = []

    with open(CSV_PATH, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)

        for row in reader:
            username = row.get('Username', '').strip()
            student_id = row.get('Id', '').strip()
            nickname = row.get('Nickname', '').strip()

            if not username or not student_id:
                continue

            result = sync_student(username, student_id, nickname)
            students_processed += 1

            if result["index_updated"]:
                indexes_updated += 1
            if result["grades_copied"]:
                files_copied += 1
            if result["calendar_copied"]:
                files_copied += 1
            if result["errors"]:
                errors.extend([(username, e) for e in result["errors"]])

    print(f"\nSummary:")
    print(f"  Students processed: {students_processed}")
    print(f"  Index files updated: {indexes_updated}")
    print(f"  Grade files copied: {files_copied}")

    if errors:
        print(f"\nMissing source files ({len(errors)}):")
        for username, error in errors[:10]:
            print(f"  {username}: {error}")
        if len(errors) > 10:
            print(f"  ... and {len(errors) - 10} more")

if __name__ == "__main__":
    main()

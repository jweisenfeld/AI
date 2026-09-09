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
PHYSICSGRADES_DIR = SCRIPT_DIR / "physicsgrades"

# Roster CSV, newest term first.  This used to be hardcoded to
# "25-26-S2-Passwords-Combined.csv", which was removed from Misc when the
# 2026-2027 year started -- so every run after that printed "CSV not found"
# and exited, and no newly enrolled student ever got a folder.  Put the
# current term at the top of this list each term.
CSV_CANDIDATES = [
    MISC_DIR / "26-27-Q1-Passwords-Combined.csv",
    MISC_DIR / "26-27-S1-Passwords-Combined.csv",
    MISC_DIR / "Current-Term-Passwords-Combined.csv",
]
CSV_PATH = next((p for p in CSV_CANDIDATES if p.exists()), CSV_CANDIDATES[0])


def load_nicknames() -> dict:
    """Map student Id -> preferred name, from whichever rosters carry one.

    The current-term roster is the authority on WHO is enrolled, but it does
    not always have a Nickname column (26-27-Q1 does not).  Without this,
    students whose preferred name differs from their legal first name would be
    greeted as "Hi Jeffery!" instead of "Hi Junior!".
    """
    nicknames = {}
    for path in CSV_CANDIDATES:
        if not path.exists():
            continue
        with open(path, 'r', encoding='utf-8-sig') as f:
            reader = csv.DictReader(f)
            if not reader.fieldnames or 'Nickname' not in reader.fieldnames:
                continue
            for row in reader:
                student_id = (row.get('Id') or '').strip()
                nickname = (row.get('Nickname') or '').strip()
                if student_id and nickname:
                    nicknames.setdefault(student_id, nickname)
    return nicknames


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

    grades_src = PHYSICSGRADES_DIR / f"{student_id}.html"

    # No grade page means this student is not in a physics class (Computer
    # Gaming / Advisory students share the same roster CSV).  Creating a folder
    # for them would publish an index.html advertising "Physics Grades" and
    # "Assignment Calendar" links that 404.
    if not grades_src.exists():
        result["skipped_no_pages"] = True
        return result

    student_dir = SCRIPT_DIR / username

    # Create folder if needed
    student_dir.mkdir(exist_ok=True)

    # Update index.html
    index_path = student_dir / "index.html"
    new_content = generate_index_html(nickname, student_id)

    if not index_path.exists():
        index_path.write_text(new_content, encoding='utf-8')
        result["index_updated"] = True
    else:
        existing = index_path.read_text(encoding='utf-8')
        already_linked = (
            f"{student_id}.html" in existing
            and f"{student_id}.calendar.html" in existing
        )
        if already_linked:
            # The page already points at both grade pages, and it has almost
            # certainly been extended elsewhere -- add_resource_to_students.py
            # adds a PhET/coach resource table, make_interactive_grade_plots.py
            # injects dashboard iframes.  Rewriting from this bare template
            # would throw all of that away, so leave it alone.
            result["index_preserved"] = True
        elif existing != new_content:
            index_path.write_text(new_content, encoding='utf-8')
            result["index_updated"] = True

    # Copy grade files
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
    indexes_preserved = 0
    skipped_no_pages = 0
    files_copied = 0
    errors = []
    nicknames = load_nicknames()
    print(f"Preferred names available for {len(nicknames)} students")

    with open(CSV_PATH, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)

        for row in reader:
            username = row.get('Username', '').strip()
            student_id = row.get('Id', '').strip()
            # Nickname from this row, else from a roster that has the column,
            # else legal first name.  A blank one would render "Hi !".
            nickname = (
                (row.get('Nickname') or '').strip()
                or nicknames.get(student_id, '')
                or (row.get('Firstname') or '').strip().split(' ')[0]
                or 'there'
            )

            if not username or not student_id:
                continue

            result = sync_student(username, student_id, nickname)

            if result.get("skipped_no_pages"):
                skipped_no_pages += 1
                continue

            students_processed += 1

            if result["index_updated"]:
                indexes_updated += 1
            if result.get("index_preserved"):
                indexes_preserved += 1
            if result["grades_copied"]:
                files_copied += 1
            if result["calendar_copied"]:
                files_copied += 1
            if result["errors"]:
                errors.extend([(username, e) for e in result["errors"]])

    print(f"\nSummary:")
    print(f"  Students processed: {students_processed}")
    print(f"  Index files created/updated: {indexes_updated}")
    print(f"  Index files left as-is (already customized): {indexes_preserved}")
    print(f"  Grade files copied: {files_copied}")
    print(f"  Skipped (no physics grade page): {skipped_no_pages}")

    if errors:
        print(f"\nMissing source files ({len(errors)}):")
        for username, error in errors[:10]:
            print(f"  {username}: {error}")
        if len(errors) > 10:
            print(f"  ... and {len(errors) - 10} more")

if __name__ == "__main__":
    main()

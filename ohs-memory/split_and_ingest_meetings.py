"""
split_and_ingest_meetings.py

Splits the Meetings (FlightLog ...).docx extraction into one .txt file
per meeting date, then ingests each group with correct school-year metadata.

Usage:
    python split_and_ingest_meetings.py
"""

import os, re, sys, subprocess
from pathlib import Path
from datetime import datetime
from dotenv import load_dotenv

load_dotenv()

MARKDOWN_FILE = r"C:\Users\johnw\Documents\orion-planning-team-onenote\meetings_extracted.md"
OUTPUT_DIR    = r"C:\Users\johnw\Documents\orion-planning-team-onenote\split_meetings"
INGEST_SCRIPT = str(Path(__file__).parent / "ingest.py")

# A meeting header is a line whose first non-space token looks like M/D/YY[YY]
# optionally followed by extra label text ("minutes", "Dr. Muhammad", etc.)
DATE_HEADER_RE = re.compile(r'^\s*(\d{1,2}/\d{1,2}/\d{2,4})(.*)')

# The second line of each meeting block is the full long date
FULL_DATE_RE = re.compile(
    r'^\s*(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)'
    r',\s+(\w+ \d{1,2}, \d{4})'
)


def parse_short_date(s: str) -> datetime | None:
    """Parse M/D/YY or M/D/YYYY."""
    for fmt in ('%m/%d/%y', '%m/%d/%Y'):
        try:
            return datetime.strptime(s.strip(), fmt)
        except ValueError:
            pass
    return None


def get_school_year(dt: datetime) -> str:
    """Meetings before Aug 2025 were pre-opening planning."""
    return '2025-26' if dt >= datetime(2025, 8, 1) else 'pre-opening'


def safe_name(s: str, maxlen: int = 40) -> str:
    return re.sub(r'[^\w\-.]', '_', s.strip())[:maxlen]


# ── Parse ──────────────────────────────────────────────────────────────────────

def split_meetings(text: str) -> list[dict]:
    meetings = []
    current  = None
    lines    = text.splitlines()

    for i, line in enumerate(lines):
        m = DATE_HEADER_RE.match(line)
        if m:
            # Flush previous meeting
            if current and len(current['content'].strip()) > 30:
                meetings.append(current)

            short_date = m.group(1)
            label      = m.group(2).strip()   # e.g. "minutes", "Dr. Muhammad"
            dt         = parse_short_date(short_date)

            # Try to get the authoritative date from the next non-blank line
            for lookahead in lines[i + 1 : i + 4]:
                fm = FULL_DATE_RE.match(lookahead)
                if fm:
                    try:
                        dt = datetime.strptime(fm.group(1), '%B %d, %Y')
                    except ValueError:
                        pass
                    break

            header_text = short_date + (' — ' + label if label else '')
            current = {
                'header':  header_text,
                'date':    dt,
                'content': f"OHS Planning Team Meeting — {header_text}\n\n",
            }
        elif current is not None:
            current['content'] += line + '\n'

    if current and len(current['content'].strip()) > 30:
        meetings.append(current)

    return meetings


# ── Write files ────────────────────────────────────────────────────────────────

def write_files(meetings: list[dict]) -> dict[str, list[Path]]:
    """Write one .txt per meeting; return {school_year: [paths]}."""
    groups: dict[str, list[Path]] = {'pre-opening': [], '2025-26': []}

    for m in meetings:
        dt   = m['date']
        year = get_school_year(dt) if dt else 'pre-opening'
        ds   = dt.strftime('%Y-%m-%d') if dt else 'unknown'

        target = Path(OUTPUT_DIR) / year
        target.mkdir(parents=True, exist_ok=True)

        fname = f"meeting_{ds}_{safe_name(m['header'])}.txt"
        fpath = target / fname
        fpath.write_text(m['content'], encoding='utf-8')
        groups[year].append(fpath)
        print(f"  [{year}]  {fname}")

    return groups


# ── Ingest ─────────────────────────────────────────────────────────────────────

def ingest_group(year: str, directory: Path) -> None:
    files = list(directory.glob('*.txt'))
    if not files:
        print(f"\n  (no {year} files to ingest)")
        return

    print(f"\nIngesting {len(files)} {year} meeting files from {directory} …")
    result = subprocess.run(
        [
            sys.executable, INGEST_SCRIPT,
            str(directory),
            '--subject', 'All-Staff',
            '--year',    year,
            '--type',    'meeting_notes',
            '--teacher', 'OHS Planning Team',
        ],
        check=False,
    )
    if result.returncode != 0:
        print(f"  ERROR: ingest.py exited with code {result.returncode}")
    else:
        print(f"  Done ({year}).")


# ── Main ───────────────────────────────────────────────────────────────────────

def main():
    md = Path(MARKDOWN_FILE).read_text(encoding='utf-8')
    meetings = split_meetings(md)
    print(f"Found {len(meetings)} meetings:\n")

    groups = write_files(meetings)

    total = sum(len(v) for v in groups.values())
    print(f"\n{total} files written to {OUTPUT_DIR}")
    print(f"  pre-opening : {len(groups['pre-opening'])}")
    print(f"  2025-26     : {len(groups['2025-26'])}")

    for year, directory in [('pre-opening', Path(OUTPUT_DIR) / 'pre-opening'),
                             ('2025-26',     Path(OUTPUT_DIR) / '2025-26')]:
        ingest_group(year, directory)

    print("\nAll done. Delete split_meetings/ folder when satisfied:")
    print(f"  rmdir /s /q \"{OUTPUT_DIR}\"")


if __name__ == '__main__':
    main()

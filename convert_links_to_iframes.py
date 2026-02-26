#!/usr/bin/env python3
"""
Convert <a> tags inside <td class="resource-iframe"> to <iframe> elements.
Uses regex on raw file content to preserve existing formatting.
Iframe dimensions match existing iframes: width="550" height="440" frameborder="0"
"""

import glob
import os
import re
import sys

DRY_RUN = "--apply" not in sys.argv

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# Match a <td class="resource-iframe"> cell that contains an <a> tag but no <iframe>.
# Captures:
#   group 1: opening <td ...> tag + leading whitespace before the <a>
#   group 2: the <a href="URL"> ... </a> block
#   group 3: the URL from the href
#   group 4: trailing whitespace + </td>
CELL_PATTERN = re.compile(
    r'(<td class="resource-iframe">\s*)(<a href="([^"]+)">[^<]*(?:<[^>]+>[^<]*</[^>]+>[^<]*)*</a>)(\s*</td>)',
    re.DOTALL
)


def make_iframe(url, before_whitespace):
    """Build an iframe tag matching the indentation of the surrounding code."""
    # The last line of before_whitespace is the indentation of the <a> tag.
    lines = before_whitespace.split("\n")
    indent = lines[-1] if lines else ""
    return f'<iframe frameborder="0" height="440" src="{url}" width="550">\n{indent}</iframe>'


def convert_file(content):
    """Return (new_content, list_of_converted_urls)."""
    conversions = []

    def replacer(m):
        before = m.group(1)   # <td ...> + whitespace
        url = m.group(3)
        after = m.group(4)    # whitespace + </td>
        conversions.append(url)
        iframe = make_iframe(url, before)
        return before + iframe + after

    new_content = CELL_PATTERN.sub(replacer, content)
    return new_content, conversions


# Collect student index.html files
pattern = os.path.join(BASE_DIR, "*", "index.html")
all_files = glob.glob(pattern)

student_files = [
    f for f in all_files
    if os.path.basename(os.path.dirname(f))[0].isalpha()
    and any(c.isdigit() for c in os.path.basename(os.path.dirname(f)))
]

changed_files = 0
total_conversions = 0

for filepath in sorted(student_files):
    folder = os.path.basename(os.path.dirname(filepath))
    with open(filepath, "r", encoding="utf-8") as f:
        original = f.read()

    new_content, conversions = convert_file(original)

    if conversions:
        changed_files += 1
        total_conversions += len(conversions)
        print(f"{'[DRY RUN] ' if DRY_RUN else ''}Updated: {folder}/index.html")
        for url in conversions:
            label = url.split("/")[-2] if url.endswith("/") else url.split("/")[-1]
            print(f"  - {label} ({url})")

        if not DRY_RUN:
            with open(filepath, "w", encoding="utf-8") as f:
                f.write(new_content)

print(f"\n{'[DRY RUN] ' if DRY_RUN else ''}Summary: {total_conversions} link(s) converted in {changed_files} file(s).")
if DRY_RUN:
    print("Run with --apply to make changes.")

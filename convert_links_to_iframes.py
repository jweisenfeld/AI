#!/usr/bin/env python3
"""
Convert resource rows that use a bare <a> link into embedded iframes.

For each pair of rows like this:
    <td class="resource-title">Unit N: Foo</td>
    <td class="resource-iframe"><a href="URL">text</a></td>

Produces:
    <td class="resource-title"><a href="URL">Unit N: Foo (click to open full screen)</a></td>
    <td class="resource-iframe"><iframe frameborder="0" height="440" width="550" src="URL"></iframe></td>

Operates on raw file content with regex to preserve all original formatting/indentation.
"""

import glob
import os
import re
import sys

DRY_RUN = "--apply" not in sys.argv

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# Match a resource-title / resource-iframe row PAIR where the iframe cell
# contains a bare <a> link (not yet an <iframe>).
#
# Groups:
#   1  <td class="resource-title">
#   2  title cell text content (with surrounding whitespace)
#   3  </td>
#   4  whitespace + </tr> + whitespace + <tr> + whitespace  (between rows)
#   5  <td class="resource-iframe">
#   6  leading whitespace inside the iframe cell
#   7  URL from the <a> href
#   8  trailing whitespace + </td>  (closes the iframe cell)
PAIR_PATTERN = re.compile(
    r'(<td class="resource-title">)'         # group 1
    r'([^<]+)'                               # group 2 – plain text (no tags yet)
    r'(</td>)'                               # group 3
    r'(\s*</tr>\s*<tr>\s*)'                  # group 4 – between the two <tr>s
    r'(<td class="resource-iframe">)'        # group 5
    r'(\s*)'                                 # group 6 – indent before <a>
    r'<a href="([^"]+)">'                    # group 7 – URL
    r'[^<]*(?:<[^>]+>[^<]*</[^>]+>[^<]*)*'  # <a> inner content (ignored)
    r'</a>'
    r'(\s*</td>)',                           # group 8 – trailing ws + close tag
    re.DOTALL,
)


def convert_file(content):
    """Return (new_content, list_of_converted_urls)."""
    conversions = []

    def replacer(m):
        title_open    = m.group(1)   # <td class="resource-title">
        title_text    = m.group(2)   # e.g. "\n      Unit 7: Systems\n     "
        title_close   = m.group(3)   # </td>
        between       = m.group(4)   # </tr>…<tr>…
        iframe_open   = m.group(5)   # <td class="resource-iframe">
        iframe_leading = m.group(6)  # e.g. "\n      "
        url           = m.group(7)
        iframe_trailing = m.group(8) # e.g. "\n     </td>"

        conversions.append(url)

        # ── title cell ─────────────────────────────────────────────────────
        stripped = title_text.strip()
        # Preserve the exact leading/trailing whitespace of the original text
        idx      = title_text.find(stripped)
        leading  = title_text[:idx]               # "\n      "
        trailing = title_text[idx + len(stripped):]  # "\n     "

        new_title = (
            f'{title_open}'
            f'{leading}'
            f'<a href="{url}">{stripped} (click to open full screen)</a>'
            f'{trailing}'
            f'{title_close}'
        )

        # ── iframe cell ────────────────────────────────────────────────────
        # Use the last line of iframe_leading as the indentation for </iframe>
        indent = iframe_leading.split("\n")[-1]   # e.g. "      "
        new_iframe = (
            f'<iframe frameborder="0" height="440" width="550" src="{url}">'
            f'\n{indent}</iframe>'
        )

        new_iframe_cell = (
            f'{iframe_open}'
            f'{iframe_leading}'
            f'{new_iframe}'
            f'{iframe_trailing}'
        )

        return f'{new_title}{between}{new_iframe_cell}'

    new_content = PAIR_PATTERN.sub(replacer, content)
    return new_content, conversions


# ── Collect student index.html files ──────────────────────────────────────
all_files = glob.glob(os.path.join(BASE_DIR, "*", "index.html"))

student_files = [
    f for f in all_files
    if os.path.basename(os.path.dirname(f))[0].isalpha()
    and any(c.isdigit() for c in os.path.basename(os.path.dirname(f)))
]

changed_files     = 0
total_conversions = 0

for filepath in sorted(student_files):
    folder = os.path.basename(os.path.dirname(filepath))
    with open(filepath, "r", encoding="utf-8") as f:
        original = f.read()

    new_content, conversions = convert_file(original)

    if conversions:
        changed_files     += 1
        total_conversions += len(conversions)
        print(f"{'[DRY RUN] ' if DRY_RUN else ''}Updated: {folder}/index.html")
        for url in conversions:
            label = url.rstrip("/").split("/")[-1]
            print(f"  - {label}  ({url})")

        if not DRY_RUN:
            with open(filepath, "w", encoding="utf-8") as f:
                f.write(new_content)

print(
    f"\n{'[DRY RUN] ' if DRY_RUN else ''}"
    f"Summary: {total_conversions} link(s) converted in {changed_files} file(s)."
)
if DRY_RUN:
    print("Run with --apply to make changes.")

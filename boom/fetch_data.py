#!/usr/bin/env python3
"""Fetch 2 Nephi verse data from bcbooks/scriptures-json and write data.json."""
import urllib.request, json, sys

URL = "https://raw.githubusercontent.com/bcbooks/scriptures-json/master/book-of-mormon.json"

def main():
    print("Fetching Book of Mormon JSON…", flush=True)
    try:
        with urllib.request.urlopen(URL, timeout=30) as r:
            bom = json.loads(r.read().decode())
    except Exception as e:
        print(f"ERROR fetching data: {e}", file=sys.stderr)
        sys.exit(1)

    # Navigate to 2 Nephi
    two_nephi = None
    for book in bom["books"]:
        if book["book"] == "2 Nephi":
            two_nephi = book
            break

    if not two_nephi:
        print("Could not find 2 Nephi in JSON!", file=sys.stderr)
        print("Available books:", [b["book"] for b in bom["books"]], file=sys.stderr)
        sys.exit(1)

    verses = []
    for chapter in two_nephi["chapters"]:
        ch_num = chapter["chapter"]
        for verse in chapter["verses"]:
            verses.append({"c": ch_num, "v": verse["verse"], "t": verse["text"]})

    out_path = "data.json"
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(verses, f, ensure_ascii=False, separators=(",", ":"))
        f.write("\n")

    print(f"Wrote {len(verses)} verses across {len(two_nephi['chapters'])} chapters to {out_path}")

if __name__ == "__main__":
    main()

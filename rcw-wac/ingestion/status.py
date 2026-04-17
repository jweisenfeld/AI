#!/usr/bin/env python3
"""
Ingestion status checker for rcw_wac_chunks.

Usage examples
--------------
# DB overview (chunk counts per corpus/title)
python status.py

# What chapters of WAC 388 are in the DB?
python status.py --corpus wac --titles 388

# Which chapters of WAC 388 are NOT yet ingested? (hits legislature website)
python status.py --corpus wac --titles 388 --missing

# Generate ingest commands for missing chapters
python status.py --corpus wac --titles 388 --missing --batch

# Multiple titles at once
python status.py --corpus rcw --titles 9A,10,35A --missing --batch

# Save batch commands to a .bat file (Windows)
python status.py --corpus rcw --titles 9A,10,35A --missing --batch > ingest_missing.bat
"""

import argparse
import os
import re
import sys
from pathlib import Path

import requests
from bs4 import BeautifulSoup
from dotenv import load_dotenv
from supabase import create_client

load_dotenv(Path(__file__).parent / '.env')

RCW_BASE = 'https://app.leg.wa.gov/RCW/default.aspx'
WAC_BASE = 'https://apps.leg.wa.gov/WAC/default.aspx'

SESSION = requests.Session()
SESSION.headers['User-Agent'] = 'RCW-WAC-Status/1.0 (educational use; contact: see psd1.net)'


# ── Supabase ──────────────────────────────────────────────────────────────────

def get_supabase():
    url = os.environ.get('SUPABASE_URL', '').strip()
    key = os.environ.get('SUPABASE_SERVICE_KEY', '').strip()
    if not url or not key:
        sys.exit('ERROR: SUPABASE_URL and SUPABASE_SERVICE_KEY must be set in .env')
    return create_client(url, key)


def _paginated_select(sb, corpus: str, col: str, title_num: str | None = None) -> list[str]:
    """Fetch one column for a corpus (and optional title) with automatic pagination."""
    PAGE = 1000
    offset = 0
    values: list[str] = []
    while True:
        q = sb.table('rcw_wac_chunks').select(col).eq('corpus', corpus)
        if title_num:
            q = q.eq('title_num', title_num)
        rows = q.range(offset, offset + PAGE - 1).execute().data or []
        values.extend(r[col] for r in rows)
        if len(rows) < PAGE:
            break
        offset += PAGE
    return values


def db_chapter_counts(sb, corpus: str, title_num: str) -> dict[str, int]:
    """Return {chapter_num: chunk_count} for a corpus/title currently in DB."""
    counts: dict[str, int] = {}
    for c in _paginated_select(sb, corpus, 'chapter_num', title_num):
        counts[c] = counts.get(c, 0) + 1
    return counts


def db_title_counts(sb, corpus: str) -> dict[str, int]:
    """Return {title_num: chunk_count} for an entire corpus."""
    counts: dict[str, int] = {}
    for t in _paginated_select(sb, corpus, 'title_num'):
        counts[t] = counts.get(t, 0) + 1
    return counts


# ── Legislature website ───────────────────────────────────────────────────────

def web_chapters_rcw(title_num: str) -> list[str]:
    try:
        html = SESSION.get(RCW_BASE, params={'cite': title_num}, timeout=20).text
    except Exception as e:
        print(f'  WARNING: could not fetch RCW title {title_num}: {e}', file=sys.stderr)
        return []
    soup = BeautifulSoup(html, 'html.parser')
    pattern = re.compile(r'cite=' + re.escape(title_num) + r'\.\d+$', re.I)
    seen: set[str] = set()
    chapters: list[str] = []
    for a in soup.find_all('a', href=pattern):
        m = re.search(r'cite=([^&]+)', a['href'])
        if m:
            num = m.group(1).strip()
            if num not in seen:
                seen.add(num)
                chapters.append(num)
    return chapters


def web_chapters_wac(title_num: str) -> list[str]:
    try:
        html = SESSION.get(WAC_BASE, params={'cite': title_num}, timeout=20).text
    except Exception as e:
        print(f'  WARNING: could not fetch WAC title {title_num}: {e}', file=sys.stderr)
        return []
    soup = BeautifulSoup(html, 'html.parser')
    pattern = re.compile(r'cite=' + re.escape(title_num) + r'-\d+[A-Za-z]?$', re.I)
    seen: set[str] = set()
    chapters: list[str] = []
    for a in soup.find_all('a', href=pattern):
        m = re.search(r'cite=([^&]+)', a['href'])
        if m:
            num = m.group(1).strip()
            if num not in seen:
                seen.add(num)
                chapters.append(num)
    return chapters


# ── Formatting ────────────────────────────────────────────────────────────────

BAR_WIDTH = 30

def progress_bar(done: int, total: int) -> str:
    if total == 0:
        return '[' + '░' * BAR_WIDTH + '] 0/0'
    filled = int(BAR_WIDTH * done / total)
    bar = '█' * filled + '░' * (BAR_WIDTH - filled)
    pct = 100 * done // total
    return f'[{bar}] {done}/{total} ({pct}%)'


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='Check ingestion status and generate missing-chapter commands.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument('--corpus', choices=['rcw', 'wac'],
                        help='Which corpus to inspect (rcw or wac)')
    parser.add_argument('--titles', metavar='28A,10,35A',
                        help='Comma-separated title numbers')
    parser.add_argument('--missing', action='store_true',
                        help='Compare DB against legislature website; show un-ingested chapters')
    parser.add_argument('--batch', action='store_true',
                        help='Print ingest.py commands for un-ingested chapters '
                             '(implies --missing; pipe to a .bat file)')
    args = parser.parse_args()

    if args.batch:
        args.missing = True

    sb = get_supabase()

    # ── No corpus/title — show DB overview ───────────────────────────────────
    if not args.corpus:
        try:
            stats = sb.rpc('rcw_wac_stats', {}).execute().data or {}
        except Exception:
            stats = {}

        print('═' * 50)
        print('  DATABASE OVERVIEW')
        print('═' * 50)
        labels = [
            ('rcw_chunks',  'RCW chunks'),
            ('wac_chunks',  'WAC chunks'),
            ('usc_chunks',  'USC chunks'),
            ('cfr_chunks',  'CFR chunks'),
            ('total_chunks','Total chunks'),
            ('rcw_titles',  'RCW titles ingested'),
            ('wac_titles',  'WAC titles ingested'),
            ('total_queries','Total queries logged'),
        ]
        for key, label in labels:
            if key in stats:
                print(f'  {label:<30} {stats[key]:>8,}')
        print()
        return

    filter_titles = [t.strip() for t in args.titles.split(',')] if args.titles else None

    # ── No titles — show per-title summary for the corpus ────────────────────
    if not filter_titles:
        counts = db_title_counts(sb, args.corpus)
        if not counts:
            print(f'No {args.corpus.upper()} data in DB yet.')
            return
        print(f'\n{args.corpus.upper()} titles in DB ({len(counts)} titles, '
              f'{sum(counts.values()):,} total chunks):')
        for title, n in sorted(counts.items()):
            print(f'  Title {title:<12} {n:>6,} chunks')
        return

    # ── Per-title chapter report ──────────────────────────────────────────────
    batch_lines: list[str] = []

    for title_num in filter_titles:
        in_db = db_chapter_counts(sb, args.corpus, title_num)

        if not args.missing:
            total_chunks = sum(in_db.values())
            print(f'\n{args.corpus.upper()} {title_num} — '
                  f'{len(in_db)} chapters, {total_chunks:,} chunks in DB')
            for chap in sorted(in_db):
                print(f'  {chap:<30} {in_db[chap]:>5} chunks')
            continue

        # --missing: fetch chapter list from legislature website
        print(f'\nFetching chapter list for {args.corpus.upper()} Title {title_num} '
              'from legislature website...', flush=True)
        if args.corpus == 'rcw':
            all_chapters = web_chapters_rcw(title_num)
        else:
            all_chapters = web_chapters_wac(title_num)

        if not all_chapters:
            print(f'  Could not retrieve chapter list for Title {title_num}.')
            continue

        ingested  = [c for c in all_chapters if c in in_db]
        missing   = [c for c in all_chapters if c not in in_db]

        print(f'\n{args.corpus.upper()} Title {title_num}  '
              + progress_bar(len(ingested), len(all_chapters)))

        if missing:
            print(f'  {len(missing)} chapter(s) not yet ingested:')
            for c in missing:
                print(f'    {c}')

            if args.batch:
                cmd = (f'python ingest.py --corpus {args.corpus} '
                       f'--chapters {",".join(missing)}')
                batch_lines.append(cmd)
        else:
            print('  All chapters ingested ✓')

    if batch_lines:
        print('\n' + '─' * 60)
        print('# Paste these commands to ingest missing chapters:')
        print('# (or run:  python status.py ... --batch > missing.bat)')
        print('─' * 60)
        for line in batch_lines:
            print(line)


if __name__ == '__main__':
    main()

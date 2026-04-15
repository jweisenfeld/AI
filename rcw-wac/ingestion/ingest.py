#!/usr/bin/env python3
"""
RCW/WAC Ingestion Pipeline
Crawls the WA Legislature website, chunks sections, embeds, stores in Supabase.

HOW THIS FITS INTO THE ARCHITECTURE
=====================================
This script runs ONCE (or whenever you want to refresh the law).
After it runs, all user queries go to Supabase — never back to leg.wa.gov.
The leg.wa.gov links in the UI are just citation links that open in a new tab.

  INGESTION (you run this):  leg.wa.gov ──► Supabase
  USER QUERIES (real-time):  Browser ──► Supabase ──► Claude

Usage:
    # Recommended Phase 1 — education law + public records
    python ingest.py --scrape --corpus rcw --titles 28A,42.56
    python ingest.py --scrape --corpus wac --titles 180

    # Dry run — crawl and parse without embedding or inserting
    python ingest.py --scrape --corpus rcw --titles 28A --dry-run

    # All RCW (slow — ~15,000 sections, takes 2-4 hours with polite rate limiting)
    python ingest.py --scrape --corpus rcw

    # Re-ingest a title after law updates
    python ingest.py --scrape --corpus rcw --titles 28A --clear

Environment variables:
    OPENAI_API_KEY       — for text-embedding-3-small
    SUPABASE_URL         — https://your-project.supabase.co
    SUPABASE_SERVICE_KEY — service_role key from Supabase → Project Settings → API
                           (NOT the anon key; service key bypasses RLS for inserts)
                           Keep this local. Never put it on the web server.
"""

import argparse
import hashlib
import os
import re
import sys
import time
from typing import Generator

import requests
import tiktoken
from bs4 import BeautifulSoup
from openai import OpenAI
from supabase import create_client

# ── Constants ─────────────────────────────────────────────────────────────────

RCW_BASE = 'https://app.leg.wa.gov/RCW/default.aspx'
WAC_BASE = 'https://apps.leg.wa.gov/WAC/default.aspx'

EMBEDDING_MODEL  = 'text-embedding-3-small'
MAX_CHUNK_TOKENS = 800
OVERLAP_TOKENS   = 100
EMBED_BATCH_SIZE = 100
INSERT_BATCH     = 250

# Polite crawl delay — the legislature server is publicly funded; be a good citizen
CRAWL_DELAY_SEC  = 0.4   # seconds between HTTP requests

ENC = tiktoken.get_encoding('cl100k_base')

# ── Token utilities ───────────────────────────────────────────────────────────

def count_tokens(text: str) -> int:
    return len(ENC.encode(text))

def chunk_text(text: str, max_tokens: int = MAX_CHUNK_TOKENS,
               overlap: int = OVERLAP_TOKENS) -> list[str]:
    tokens = ENC.encode(text)
    if len(tokens) <= max_tokens:
        return [text]
    chunks = []
    start = 0
    while start < len(tokens):
        end = min(start + max_tokens, len(tokens))
        chunks.append(ENC.decode(tokens[start:end]))
        if end >= len(tokens):
            break
        start += max_tokens - overlap
    return chunks

# ── HTTP helpers ──────────────────────────────────────────────────────────────

SESSION = requests.Session()
SESSION.headers['User-Agent'] = (
    'RCW-WAC-Ingestion/1.0 '
    '(educational use; contact: see psd1.net)'
)

def fetch(url: str, params: dict | None = None, retries: int = 3) -> str | None:
    """Fetch a URL, return text or None on permanent failure."""
    for attempt in range(retries):
        try:
            resp = SESSION.get(url, params=params, timeout=20)
            resp.raise_for_status()
            time.sleep(CRAWL_DELAY_SEC)
            return resp.text
        except requests.RequestException as e:
            if attempt == retries - 1:
                print(f'  FETCH ERROR {url}: {e}', file=sys.stderr)
                return None
            wait = 2 ** (attempt + 1)
            print(f'  Retry {attempt+1}/{retries} in {wait}s...', file=sys.stderr)
            time.sleep(wait)
    return None

# ── RCW crawler ───────────────────────────────────────────────────────────────
#
# The WA Legislature RCW site has this URL/page hierarchy:
#
#   /RCW/default.aspx              → title list   (e.g. Title 28A, Title 42 ...)
#   /RCW/default.aspx?cite=28A     → chapter list (e.g. 28A.400, 28A.405 ...)
#   /RCW/default.aspx?cite=28A.400 → section list (e.g. 28A.400.010 ...)
#   /RCW/default.aspx?cite=28A.400.010 → section text
#
# If the HTML structure changes, adjust the CSS selectors in the functions below.
# Run with --dry-run to test parsing without spending API credits.

def rcw_list_titles(filter_titles: list[str] | None = None) -> list[dict]:
    """Fetch the RCW title list. Returns [{num, name, url}]."""
    html = fetch(RCW_BASE)
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    titles = []
    # Title links look like: <a href="default.aspx?cite=28A">Title 28A ...</a>
    for a in soup.find_all('a', href=re.compile(r'cite=\d+[A-Z]?$', re.I)):
        href  = a.get('href', '')
        text  = a.get_text(' ', strip=True)
        cite  = re.search(r'cite=([^&]+)', href)
        if not cite:
            continue
        num = cite.group(1).strip()
        if filter_titles and num not in filter_titles:
            continue
        titles.append({'num': num, 'name': text, 'url': f'{RCW_BASE}?cite={num}'})

    if not titles and filter_titles:
        # Fallback: construct URLs directly from requested title numbers
        for num in filter_titles:
            titles.append({'num': num, 'name': f'Title {num}', 'url': f'{RCW_BASE}?cite={num}'})

    return titles


def rcw_list_chapters(title_num: str) -> list[dict]:
    """Fetch chapters for one RCW title. Returns [{num, name}]."""
    html = fetch(RCW_BASE, {'cite': title_num})
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    chapters = []
    # Chapter links: <a href="default.aspx?cite=28A.400">RCW Chapter 28A.400 ...</a>
    for a in soup.find_all('a', href=re.compile(r'cite=' + re.escape(title_num) + r'\.\d+$', re.I)):
        href = a.get('href', '')
        cite = re.search(r'cite=([^&]+)', href)
        if not cite:
            continue
        num  = cite.group(1).strip()
        name = a.get_text(' ', strip=True)
        # Strip leading "RCW Chapter X.Y — " prefix if present
        name = re.sub(r'^RCW\s+Chapter\s+[\d\.]+\s*[-—]\s*', '', name).strip()
        chapters.append({'num': num, 'name': name})

    return chapters


def rcw_list_sections(chapter_num: str) -> list[str]:
    """Fetch section citation numbers for one RCW chapter."""
    html = fetch(RCW_BASE, {'cite': chapter_num})
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    # Pattern: cite=28A.400.010
    pattern = re.compile(r'cite=' + re.escape(chapter_num) + r'\.\d+$', re.I)
    seen = set()
    sections = []
    for a in soup.find_all('a', href=pattern):
        href = a.get('href', '')
        cite = re.search(r'cite=([^&]+)', href)
        if cite:
            num = cite.group(1).strip()
            if num not in seen:
                seen.add(num)
                sections.append(num)
    return sections


def rcw_fetch_section(cite: str, title_num: str, title_name: str,
                       chapter_num: str, chapter_name: str) -> dict | None:
    """Fetch and parse one RCW section. Returns section dict or None."""
    html = fetch(RCW_BASE, {'cite': cite})
    if not html:
        return None

    soup = BeautifulSoup(html, 'html.parser')

    # The section heading is typically in an <h3> or <h2> near the top of content
    heading = ''
    for tag in ('h3', 'h2', 'h1'):
        h = soup.find(tag)
        if h:
            heading = h.get_text(' ', strip=True)
            # Remove citation prefix ("RCW 28A.400.010") from heading text
            heading = re.sub(r'^RCW\s+[\d\.]+\s*[-—]\s*', '', heading).strip()
            break

    # Section body — try common container IDs/classes used by the legislature site
    content = ''
    for selector in ('#contentWrapper', '#rcwContent', '.rcwSection', '#rcwSection',
                     'div.rcw-section', 'div#rcwTextBody', 'div.body-text'):
        div = soup.select_one(selector)
        if div:
            content = div.get_text('\n', strip=True)
            break

    if not content:
        # Generic fallback: grab all <p> tags in the main content area
        main = soup.find('div', id=re.compile(r'content|main|body', re.I)) or soup.find('body')
        if main:
            paragraphs = [p.get_text(' ', strip=True) for p in main.find_all('p') if p.get_text(strip=True)]
            content = '\n'.join(paragraphs)

    content = re.sub(r'\s{3,}', '\n', content).strip()
    content = re.sub(r'^\[.*?\]\s*', '', content)   # strip bracketed notes at start

    if not content or len(content) < 30:
        return None

    sec_num = cite.rsplit('.', 1)[-1] if '.' in cite else cite

    return {
        'corpus':          'rcw',
        'title_num':       title_num,
        'title_name':      title_name,
        'chapter_num':     chapter_num,
        'chapter_name':    chapter_name,
        'section_num':     sec_num,
        'section_id':      f'RCW {cite}',
        'section_heading': heading,
        'content':         content,
        'source_url':      f'{RCW_BASE}?cite={cite}',
    }


def crawl_rcw(filter_titles: list[str] | None = None) -> Generator[dict, None, None]:
    """
    Generator that yields section dicts by crawling the RCW site.
    Traverses: title list → chapters → sections → section text.
    """
    titles = rcw_list_titles(filter_titles)
    if not titles:
        print('ERROR: could not fetch RCW title list.', file=sys.stderr)
        return

    print(f'Found {len(titles)} title(s) to crawl.')

    for title in titles:
        t_num  = title['num']
        t_name = re.sub(r'^Title\s+\S+\s*[-—]\s*', '', title['name']).strip() or title['name']
        print(f'\n  Title {t_num}: {t_name}')

        chapters = rcw_list_chapters(t_num)
        if not chapters:
            print(f'    WARNING: no chapters found for Title {t_num}')
            continue

        print(f'    {len(chapters)} chapters')
        for chap in chapters:
            c_num  = chap['num']
            c_name = chap['name']

            section_cites = rcw_list_sections(c_num)
            for cite in section_cites:
                section = rcw_fetch_section(cite, t_num, t_name, c_num, c_name)
                if section:
                    yield section


# ── WAC crawler ───────────────────────────────────────────────────────────────
#
# WAC URL hierarchy mirrors RCW:
#   /WAC/default.aspx              → title list
#   /WAC/default.aspx?cite=180     → chapter list
#   /WAC/default.aspx?cite=180-16  → section list
#   /WAC/default.aspx?cite=180-16-195 → section text

def wac_list_titles(filter_titles: list[str] | None = None) -> list[dict]:
    """Fetch WAC title list."""
    html = fetch(WAC_BASE)
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    titles = []
    for a in soup.find_all('a', href=re.compile(r'cite=\d+$', re.I)):
        href = a.get('href', '')
        cite = re.search(r'cite=(\d+)', href)
        if not cite:
            continue
        num = cite.group(1)
        if filter_titles and num not in filter_titles:
            continue
        name = a.get_text(' ', strip=True)
        titles.append({'num': num, 'name': name, 'url': f'{WAC_BASE}?cite={num}'})

    if not titles and filter_titles:
        for num in filter_titles:
            titles.append({'num': num, 'name': f'WAC Title {num}', 'url': f'{WAC_BASE}?cite={num}'})

    return titles


def wac_list_chapters(title_num: str) -> list[dict]:
    html = fetch(WAC_BASE, {'cite': title_num})
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    chapters = []
    pattern = re.compile(r'cite=' + re.escape(title_num) + r'-\d+$', re.I)
    for a in soup.find_all('a', href=pattern):
        href = a.get('href', '')
        cite = re.search(r'cite=([^&]+)', href)
        if not cite:
            continue
        num  = cite.group(1).strip()
        name = a.get_text(' ', strip=True)
        name = re.sub(r'^WAC\s+Chapter\s+[\d\-]+\s*[-—]\s*', '', name).strip()
        chapters.append({'num': num, 'name': name})
    return chapters


def wac_list_sections(chapter_num: str) -> list[str]:
    html = fetch(WAC_BASE, {'cite': chapter_num})
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    pattern = re.compile(r'cite=' + re.escape(chapter_num) + r'-\d+$', re.I)
    seen = set()
    sections = []
    for a in soup.find_all('a', href=pattern):
        href = a.get('href', '')
        cite = re.search(r'cite=([^&]+)', href)
        if cite:
            num = cite.group(1).strip()
            if num not in seen:
                seen.add(num)
                sections.append(num)
    return sections


def wac_fetch_section(cite: str, title_num: str, title_name: str,
                       chapter_num: str, chapter_name: str) -> dict | None:
    html = fetch(WAC_BASE, {'cite': cite})
    if not html:
        return None

    soup = BeautifulSoup(html, 'html.parser')

    heading = ''
    for tag in ('h3', 'h2', 'h1'):
        h = soup.find(tag)
        if h:
            heading = h.get_text(' ', strip=True)
            heading = re.sub(r'^WAC\s+[\d\-]+\s*[-—]\s*', '', heading).strip()
            break

    content = ''
    for selector in ('#contentWrapper', '#wacContent', '.wacSection', 'div.wac-section',
                     'div#wacTextBody', 'div.body-text', '#rcwContent'):
        div = soup.select_one(selector)
        if div:
            content = div.get_text('\n', strip=True)
            break

    if not content:
        main = soup.find('div', id=re.compile(r'content|main|body', re.I)) or soup.find('body')
        if main:
            paragraphs = [p.get_text(' ', strip=True) for p in main.find_all('p') if p.get_text(strip=True)]
            content = '\n'.join(paragraphs)

    content = re.sub(r'\s{3,}', '\n', content).strip()
    if not content or len(content) < 30:
        return None

    sec_num = cite.rsplit('-', 1)[-1] if '-' in cite else cite

    return {
        'corpus':          'wac',
        'title_num':       title_num,
        'title_name':      title_name,
        'chapter_num':     chapter_num,
        'chapter_name':    chapter_name,
        'section_num':     sec_num,
        'section_id':      f'WAC {cite}',
        'section_heading': heading,
        'content':         content,
        'source_url':      f'{WAC_BASE}?cite={cite}',
    }


def crawl_wac(filter_titles: list[str] | None = None) -> Generator[dict, None, None]:
    titles = wac_list_titles(filter_titles)
    if not titles:
        print('ERROR: could not fetch WAC title list.', file=sys.stderr)
        return

    print(f'Found {len(titles)} title(s) to crawl.')

    for title in titles:
        t_num  = title['num']
        t_name = re.sub(r'^Title\s+\S+\s*[-—]\s*', '', title['name']).strip() or title['name']
        print(f'\n  Title {t_num}: {t_name}')

        chapters = wac_list_chapters(t_num)
        if not chapters:
            print(f'    WARNING: no chapters found for WAC Title {t_num}')
            continue

        print(f'    {len(chapters)} chapters')
        for chap in chapters:
            c_num  = chap['num']
            c_name = chap['name']

            section_cites = wac_list_sections(c_num)
            for cite in section_cites:
                section = wac_fetch_section(cite, t_num, t_name, c_num, c_name)
                if section:
                    yield section


# ── Chunking ──────────────────────────────────────────────────────────────────

def build_chunks(sections: list[dict]) -> list[dict]:
    chunks = []
    for sec in sections:
        prefix = sec['section_id']
        if sec['section_heading']:
            prefix += f" — {sec['section_heading']}"
        prefix += '\n\n'

        for i, body in enumerate(chunk_text(sec['content'])):
            full_content = prefix + body
            content_hash = hashlib.sha256(full_content.encode()).hexdigest()[:32]
            chunks.append({**sec,
                'content':      full_content,
                'chunk_index':  i,
                'token_count':  count_tokens(full_content),
                'content_hash': content_hash,
            })
    return chunks


# ── Embedding ─────────────────────────────────────────────────────────────────

def embed_texts(client: OpenAI, texts: list[str]) -> list[list[float]]:
    embeddings = []
    total = len(texts)
    for i in range(0, total, EMBED_BATCH_SIZE):
        batch = texts[i:i + EMBED_BATCH_SIZE]
        resp  = client.embeddings.create(input=batch, model=EMBEDDING_MODEL)
        embeddings.extend(item.embedding for item in resp.data)
        done = min(i + EMBED_BATCH_SIZE, total)
        print(f'  Embedded {done}/{total}...', end='\r', flush=True)
        if done < total:
            time.sleep(0.05)
    print()
    return embeddings


# ── Supabase ──────────────────────────────────────────────────────────────────

def fetch_existing_hashes(sb) -> set[str]:
    result = sb.table('rcw_wac_chunks').select('content_hash').execute()
    return {r['content_hash'] for r in (result.data or [])}


def insert_chunks(sb, chunks: list[dict], embeddings: list[list[float]]) -> int:
    inserted = 0
    for i in range(0, len(chunks), INSERT_BATCH):
        batch      = chunks[i:i + INSERT_BATCH]
        batch_embs = embeddings[i:i + INSERT_BATCH]
        rows = [{
            'corpus':          c['corpus'],
            'title_num':       c['title_num'],
            'title_name':      c['title_name'],
            'chapter_num':     c['chapter_num'],
            'chapter_name':    c['chapter_name'],
            'section_num':     c['section_num'],
            'section_id':      c['section_id'],
            'section_heading': c['section_heading'],
            'chunk_index':     c['chunk_index'],
            'content':         c['content'],
            'token_count':     c['token_count'],
            'source_url':      c['source_url'],
            'content_hash':    c['content_hash'],
            'embedding':       emb,
        } for c, emb in zip(batch, batch_embs)]
        sb.table('rcw_wac_chunks').insert(rows).execute()
        inserted += len(rows)
        print(f'  Inserted {inserted}/{len(chunks)} rows...', end='\r', flush=True)
    print()
    return inserted


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='Scrape WA Legislature website → embed → store in Supabase.\n'
                    'Run once to build the database; queries go to Supabase, not leg.wa.gov.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('--corpus', required=True, choices=['rcw', 'wac'],
                        help='Which code to crawl')
    parser.add_argument('--titles', metavar='28A,42.56,180',
                        help='Comma-separated title numbers to limit the crawl '
                             '(recommended for Phase 1). Omit for full corpus.')
    parser.add_argument('--dry-run', action='store_true',
                        help='Crawl and parse without embedding or inserting. '
                             'Use this first to verify selectors are working.')
    parser.add_argument('--clear', action='store_true',
                        help='Delete existing DB rows for these titles before re-ingesting '
                             '(useful after a law update)')
    parser.add_argument('--delay', type=float, default=CRAWL_DELAY_SEC,
                        help=f'Seconds between HTTP requests (default {CRAWL_DELAY_SEC})')
    args = parser.parse_args()

    global CRAWL_DELAY_SEC
    CRAWL_DELAY_SEC = args.delay

    filter_titles = [t.strip() for t in args.titles.split(',')] if args.titles else None

    openai_key   = os.environ.get('OPENAI_API_KEY', '').strip()
    supabase_url = os.environ.get('SUPABASE_URL', '').strip()
    # Ingestion requires the SERVICE ROLE key (bypasses RLS to INSERT chunks).
    # Never use the anon key here — with RLS enabled it cannot insert rows.
    # Keep the service key off the web server; ingest.py runs locally only.
    supabase_key = os.environ.get('SUPABASE_SERVICE_KEY', '').strip()

    if not args.dry_run:
        if not openai_key:   sys.exit('ERROR: OPENAI_API_KEY not set.')
        if not supabase_url: sys.exit('ERROR: SUPABASE_URL not set.')
        if not supabase_key: sys.exit('ERROR: SUPABASE_SERVICE_KEY not set.\n'
                                      'Find it in Supabase → Project Settings → API → service_role key.\n'
                                      'Keep it local. Never put it in the PHP proxy or commit it to git.')

    oa = OpenAI(api_key=openai_key) if not args.dry_run else None
    sb = create_client(supabase_url, supabase_key) if not args.dry_run else None

    # ── Crawl ────────────────────────────────────────────────────────────────
    print(f'Crawling {args.corpus.upper()}' +
          (f' titles: {filter_titles}' if filter_titles else ' (full corpus)'))
    if args.dry_run:
        print('DRY RUN — no embedding or DB writes.')

    crawler = crawl_rcw(filter_titles) if args.corpus == 'rcw' else crawl_wac(filter_titles)

    all_chunks: list[dict] = []
    section_count = 0

    for section in crawler:
        section_count += 1
        chunks = build_chunks([section])
        all_chunks.extend(chunks)
        print(f'  {section["section_id"]} ({len(chunks)} chunk(s), {section_count} sections so far)',
              end='\r', flush=True)

        # Stream inserts: embed + insert in batches during crawl to save memory
        # and survive interruption (already-inserted chunks are deduped on restart).
        if not args.dry_run and len(all_chunks) >= 500:
            _flush_chunks(all_chunks, oa, sb, args.clear, section_count == len(all_chunks))
            all_chunks = []

    print(f'\nCrawl complete: {section_count} sections → {len(all_chunks)} remaining chunks')

    if args.dry_run:
        # Print sample output to verify parsing
        sample_sections = []
        seen_ids = set()
        for c in all_chunks[:20]:
            if c['section_id'] not in seen_ids:
                seen_ids.add(c['section_id'])
                sample_sections.append(c)
                if len(sample_sections) >= 5:
                    break

        print('\n── Sample output (first 5 sections) ────────────────────')
        for s in sample_sections:
            print(f"\n{s['section_id']}: {s['section_heading']}")
            print(f"  chunk_index={s['chunk_index']} | tokens={s['token_count']}")
            preview = s['content'][:200].replace('\n', ' ')
            print(f"  {preview}...")
        print(f'\nWould ingest: {len(all_chunks)} total chunks')
        print('If output looks good, rerun without --dry-run.')
        return

    if all_chunks:
        _flush_chunks(all_chunks, oa, sb, args.clear, done=True)

    print('\nIngestion complete.')
    if sb:
        stats = sb.rpc('rcw_wac_stats', {}).execute()
        if stats.data:
            d = stats.data
            print(f'  Corpus totals: {d.get("rcw_chunks",0)} RCW chunks, '
                  f'{d.get("wac_chunks",0)} WAC chunks')


def _flush_chunks(chunks: list[dict], oa, sb, clear_first: bool, done: bool):
    """Dedup, embed, and insert a batch of chunks."""
    if clear_first and done:
        title_nums = list({c['title_num'] for c in chunks})
        corpus     = chunks[0]['corpus']
        for t in title_nums:
            sb.table('rcw_wac_chunks').delete() \
              .eq('corpus', corpus).eq('title_num', t).execute()
        existing_hashes: set[str] = set()
    else:
        existing_hashes = fetch_existing_hashes(sb)

    new_chunks = [c for c in chunks if c['content_hash'] not in existing_hashes]
    if not new_chunks:
        return

    print(f'\nEmbedding {len(new_chunks)} chunks...')
    embeddings = embed_texts(oa, [c['content'] for c in new_chunks])
    insert_chunks(sb, new_chunks, embeddings)


if __name__ == '__main__':
    main()

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
    python ingest.py --corpus rcw --titles 28A,42.56
    python ingest.py --corpus wac --titles 180

    # Dry run — crawl and parse without embedding or inserting
    python ingest.py --corpus rcw --titles 28A --dry-run

    # All RCW (slow — ~15,000 sections, takes 2-4 hours with polite rate limiting)
    python ingest.py --corpus rcw

    # Re-ingest a title after law updates
    python ingest.py --corpus rcw --titles 28A --clear

    # Pasco Municipal Code from local HTML export (Title 25 only, dry run)
    python ingest.py --corpus pmc --titles 25 --pmc-file ../../pmc1/Pasco-Municipal-Code.html --dry-run

    # Pasco Municipal Code full ingest by title range
    python ingest.py --corpus pmc --titles 1,2,3,4,5,6,8,9,10,12,13,14,15,16,17,18,19,20,21,23,24,25,28,29 --pmc-file ../../pmc1/Pasco-Municipal-Code.html

Environment variables:
    OPENAI_API_KEY       — for text-embedding-3-small
    SUPABASE_URL         — https://your-project.supabase.co
    SUPABASE_SERVICE_KEY — service_role key from Supabase → Project Settings → API
                           (NOT the anon key; service key bypasses RLS for inserts)
                           Keep this local. Never put it on the web server.
"""

import argparse
import hashlib
from pathlib import Path
import os
import re
import sys
import time
from typing import Generator

import requests
import tiktoken
from bs4 import BeautifulSoup
from bs4.element import NavigableString, Tag
from dotenv import load_dotenv
from openai import OpenAI
from supabase import create_client

# Load .env from the same directory as this script (rcw-wac/ingestion/.env)
load_dotenv(Path(__file__).parent / '.env')

# ── Constants ─────────────────────────────────────────────────────────────────

RCW_BASE = 'https://app.leg.wa.gov/RCW/default.aspx'
WAC_BASE = 'https://apps.leg.wa.gov/WAC/default.aspx'
CFR_BASE = 'https://www.ecfr.gov/current'
USC_BASE = 'https://www.law.cornell.edu/uscode/text'
PMC_DEFAULT_HTML = str((Path(__file__).resolve().parents[2] / 'pmc1' / 'Pasco-Municipal-Code.html'))

EMBEDDING_MODEL  = 'text-embedding-3-small'
MAX_CHUNK_TOKENS = 800
OVERLAP_TOKENS   = 100
EMBED_BATCH_SIZE = 100
INSERT_BATCH     = 10    # small batches — HNSW index update time grows with table size

# Polite crawl delay — the legislature server is publicly funded; be a good citizen
CRAWL_DELAY_SEC  = 0.4   # seconds between HTTP requests

ENC = None

def _get_encoder():
    global ENC
    if ENC is None:
        ENC = tiktoken.get_encoding('cl100k_base')
    return ENC

# ── Token utilities ───────────────────────────────────────────────────────────

def count_tokens(text: str) -> int:
    return len(_get_encoder().encode(text))

def chunk_text(text: str, max_tokens: int = MAX_CHUNK_TOKENS,
               overlap: int = OVERLAP_TOKENS) -> list[str]:
    enc = _get_encoder()
    tokens = enc.encode(text)
    if len(tokens) <= max_tokens:
        return [text]
    chunks = []
    start = 0
    while start < len(tokens):
        end = min(start + max_tokens, len(tokens))
        chunks.append(enc.decode(tokens[start:end]))
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

def _strip_pdf_links(text: str) -> str:
    """Remove 'PDF RCW/WAC X.X.X' download-link artifacts from scraped text."""
    return re.sub(r'\bPDF\s+(?:RCW|WAC)\s+[\d][.\dA-Za-z\-]*\s*', '', text, flags=re.I).strip()


def _heading_from_content(section_id: str, content: str) -> str:
    """
    Extract section heading from the start of content when the h-tag had no title text.
    WA Legislature embeds the cite + heading at the start of each section body:
      'WAC 180-08-001  Purpose and authority. (1) ...'
      'RCW 28A.150.010 Public schools. Public schools means ...'
    """
    text = content
    # Strip section_id from start (WAC pages repeat the full cite; RCW sometimes does too)
    cite = re.sub(r'^(?:RCW|WAC)\s+', '', section_id)   # bare number: '180-08-001'
    text = re.sub(r'^(?:(?:RCW|WAC)\s+)?' + re.escape(cite) + r'\s+', '', text).strip()
    # Grab text up to the first numbered paragraph marker, period+space, or newline
    m = re.match(r'^(.{3,150}?)(?=\s*\(\d+\)|\.\s|\n|$)', text)
    if m:
        return m.group(1).rstrip('. ').strip()
    return ''


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
            heading = _strip_pdf_links(heading)
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

    content = _strip_pdf_links(content)
    content = re.sub(r'\s{3,}', '\n', content).strip()
    content = re.sub(r'^\[.*?\]\s*', '', content)   # strip bracketed notes at start

    if not content or len(content) < 30:
        return None

    if not heading:
        heading = _heading_from_content(f'RCW {cite}', content)

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


def crawl_rcw(filter_titles: list[str] | None = None,
              filter_chapters: list[str] | None = None) -> Generator[dict, None, None]:
    """
    Generator that yields section dicts by crawling the RCW site.
    Traverses: title list → chapters → sections → section text.
    """
    # If chapter filter given without title filter, derive titles automatically
    if filter_chapters and not filter_titles:
        filter_titles = list({c.rsplit('.', 1)[0] for c in filter_chapters})
    titles = rcw_list_titles(filter_titles)
    if not titles:
        print('ERROR: could not fetch RCW title list.', file=sys.stderr)
        return

    print(f'Found {len(titles)} title(s) to crawl.')

    for title in titles:
        t_num  = title['num']
        t_name = re.sub(r'^Title\s+\S+\s*[-—]\s*', '', title['name']).strip() or title['name']
        t_name = ' '.join(t_name.split())
        print(f'\n  Title {t_num}: {t_name}')

        chapters = rcw_list_chapters(t_num)
        if not chapters:
            print(f'    WARNING: no chapters found for Title {t_num}')
            continue

        filtered = [c for c in chapters if not filter_chapters or c['num'] in filter_chapters]
        print(f'    {len(chapters)} chapters ({len(filtered)} after chapter filter)')
        for chap in filtered:
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
    pattern = re.compile(r'cite=' + re.escape(title_num) + r'-\d+[A-Za-z]?$', re.I)
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
            heading = _strip_pdf_links(heading)
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

    content = _strip_pdf_links(content)
    content = re.sub(r'\s{3,}', '\n', content).strip()
    if not content or len(content) < 30:
        return None

    if not heading:
        heading = _heading_from_content(f'WAC {cite}', content)

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


def crawl_wac(filter_titles: list[str] | None = None,
              filter_chapters: list[str] | None = None) -> Generator[dict, None, None]:
    # If chapter filter given without title filter, derive titles automatically
    if filter_chapters and not filter_titles:
        filter_titles = list({c.split('-')[0] for c in filter_chapters})
    titles = wac_list_titles(filter_titles)
    if not titles:
        print('ERROR: could not fetch WAC title list.', file=sys.stderr)
        return

    print(f'Found {len(titles)} title(s) to crawl.')

    for title in titles:
        t_num  = title['num']
        t_name = re.sub(r'^Title\s+\S+\s*[-—]\s*', '', title['name']).strip() or title['name']
        t_name = ' '.join(t_name.split())
        print(f'\n  Title {t_num}: {t_name}')

        chapters = wac_list_chapters(t_num)
        if not chapters:
            print(f'    WARNING: no chapters found for WAC Title {t_num}')
            continue

        filtered = [c for c in chapters if not filter_chapters or c['num'] in filter_chapters]
        print(f'    {len(chapters)} chapters ({len(filtered)} after chapter filter)')
        for chap in filtered:
            c_num  = chap['num']
            c_name = chap['name']

            section_cites = wac_list_sections(c_num)
            for cite in section_cites:
                section = wac_fetch_section(cite, t_num, t_name, c_num, c_name)
                if section:
                    yield section


# ── CFR crawler (eCFR XML API) ────────────────────────────────────────────────
#
# eCFR provides a versioned XML API — the website is React-rendered so plain
# HTTP requests only get empty HTML.  Download the full-title XML once, then
# parse the requested part out of it (no per-section HTTP requests needed).
#
# XML structure (SGML-style DIV elements):
#   DIV1  TYPE=TITLE
#   └─ DIV5 TYPE=PART N="300"
#      └─ DIV8 TYPE=SECTION N="300.1"
#         ├─ HEAD  (section heading)
#         └─ P / FP  (paragraph text)
#
# --titles 34 --chapters 300  →  34 CFR Part 300 (IDEA regulations)
# section_id format: '34 CFR § 300.1'

def cfr_fetch_title_xml(title_num: str) -> str | None:
    """Download eCFR full-title XML. Tries eCFR versioner API, then govinfo.gov fallback."""
    from datetime import date
    today = date.today().strftime('%Y-%m-%d')
    # eCFR versioner API: /api/versioner/v1/full/{date}/title-{n}.xml
    # Today's date may not have a published version; try progressively older dates.
    for d in [today, '2025-04-01', '2025-01-01', '2024-10-01', '2024-01-01']:
        url = f'https://www.ecfr.gov/api/versioner/v1/full/{d}/title-{title_num}.xml'
        xml = fetch(url)
        if xml and len(xml) > 5000:
            return xml
    # Fallback: GPO govinfo.gov bulk eCFR XML (updated nightly, reliable plain HTTP)
    url = f'https://www.govinfo.gov/bulkdata/ECFR/title-{title_num}/ECFR-title{title_num}.xml'
    xml = fetch(url)
    if xml and len(xml) > 5000:
        return xml
    return None


def cfr_parse_part(xml: str, title_num: str, part_num: str,
                   title_name: str, part_name: str) -> list[dict]:
    """
    Parse all sections from one CFR part.
    Handles two XML formats:
      eCFR versioner: DIV5[TYPE=PART N=300] > DIV8[TYPE=SECTION N=300.1]
      govinfo.gov:    PART[N=300] > SECTION > SECTNO + SUBJECT + P
    """
    soup = BeautifulSoup(xml, 'lxml-xml')

    # ── Locate the PART element (eCFR format first) ───────────────────────────
    part = (
        soup.find('DIV5', attrs={'N': part_num, 'TYPE': 'PART'}) or
        soup.find('DIV5', attrs={'N': part_num})
    )
    if not part:
        for div in soup.find_all(re.compile(r'^DIV\d+$')):
            head = div.find('HEAD')
            if head and re.search(rf'\bPART\s+{re.escape(part_num)}\b', head.get_text()):
                part = div
                break

    # govinfo.gov format: <PART N="300"> or search by EAR/HD text
    if not part:
        part = soup.find('PART', attrs={'N': part_num})
    if not part:
        for p in soup.find_all('PART'):
            ear = p.find('EAR')
            hd  = p.find('HD')
            if (ear and f'Pt. {part_num}' in ear.get_text()) or \
               (hd  and f'PART {part_num}' in hd.get_text()):
                part = p
                break

    if not part:
        return []

    def _make_row(sec_n: str, heading: str, content: str) -> dict:
        section_id = f'{title_num} CFR § {sec_n}'
        if not heading:
            heading = _heading_from_content(section_id, content)
        return {
            'corpus':          'cfr',
            'title_num':       title_num,
            'title_name':      title_name,
            'chapter_num':     part_num,
            'chapter_name':    part_name,
            'section_num':     sec_n,
            'section_id':      section_id,
            'section_heading': heading,
            'content':         content,
            'source_url':      f'{CFR_BASE}/title-{title_num}/part-{part_num}/section-{sec_n}',
        }

    sections = []

    # ── eCFR versioner: DIV8 elements ─────────────────────────────────────────
    for sec in part.find_all('DIV8'):
        sec_n = sec.get('N', '')
        if not sec_n:
            continue
        head    = sec.find('HEAD')
        heading = head.get_text(' ', strip=True) if head else ''
        heading = re.sub(r'^§\s*[\d\.]+\w*\.?\s*', '', heading).strip()
        paras   = [p.get_text(' ', strip=True) for p in sec.find_all(['P', 'FP'])
                   if p.get_text(strip=True)]
        content = re.sub(r'\s{3,}', '\n', '\n'.join(paras).strip())
        if content and len(content) >= 30:
            sections.append(_make_row(sec_n, heading, content))

    if sections:
        return sections

    # ── govinfo.gov: SECTION > SECTNO + SUBJECT + P ───────────────────────────
    for sec in part.find_all('SECTION'):
        sectno_tag = sec.find('SECTNO')
        if not sectno_tag:
            continue
        m = re.search(r'§\s*([\d\.]+\w*)', sectno_tag.get_text())
        if not m:
            continue
        sec_n       = m.group(1)
        subject_tag = sec.find('SUBJECT')
        heading     = subject_tag.get_text(' ', strip=True) if subject_tag else ''
        paras       = [p.get_text(' ', strip=True) for p in sec.find_all(['P', 'FP'])
                       if p.get_text(strip=True)]
        content     = re.sub(r'\s{3,}', '\n', '\n'.join(paras).strip())
        if content and len(content) >= 30:
            sections.append(_make_row(sec_n, heading, content))

    return sections


def crawl_cfr(filter_titles: list[str] | None = None,
              filter_chapters: list[str] | None = None) -> Generator[dict, None, None]:
    """
    Crawl CFR parts from the eCFR versioned XML API.
    Requires --titles (CFR title number, e.g. 34) and --chapters (part number, e.g. 300).
    Example: --corpus cfr --titles 34 --chapters 300
    """
    if not filter_titles:
        print('ERROR: --titles required for CFR  (e.g. --titles 34 --chapters 300)',
              file=sys.stderr)
        return
    if not filter_chapters:
        print('ERROR: --chapters required for CFR (e.g. --chapters 300,301)',
              file=sys.stderr)
        return

    for title_num in filter_titles:
        title_name = f'CFR Title {title_num}'
        print(f'\n  Downloading CFR Title {title_num} XML (may take 10–30 s)...')
        xml = cfr_fetch_title_xml(title_num)
        if not xml:
            print(f'  ERROR: Could not fetch CFR Title {title_num} XML', file=sys.stderr)
            continue
        print(f'  XML downloaded ({len(xml):,} bytes).')

        for part_num in filter_chapters:
            part_name = f'Part {part_num}'
            print(f'\n  CFR Title {title_num} Part {part_num}')
            sections = cfr_parse_part(xml, title_num, part_num, title_name, part_name)
            print(f'    {len(sections)} sections')
            yield from sections


# ── USC crawler (Cornell LII) ─────────────────────────────────────────────────
#
# Cornell LII URL hierarchy:
#   /uscode/text/34                     → chapter list for Title 34
#   /uscode/text/20/chapter-33          → section list for Chapter 33
#   /uscode/text/20/1415                → individual section
#
# --titles 20 --chapters 33  →  20 USC Chapter 33 (IDEA statute)
# section_id format: '20 USC § 1415'
#
# Cornell LII chapter pages organize sections under subchapters, so direct
# section links may not appear on the chapter listing page.  USC_CHAPTER_SECTIONS
# provides known section ranges as a fallback for important chapters.

USC_CHAPTER_SECTIONS: dict[tuple[str, str], list[str]] = {
    # IDEA — Individuals with Disabilities Education Act, 20 USC Chapter 33
    # Sparse numbering by subchapter — not every integer in 1400–1482 exists:
    #   Subchapter I  (General):            §§ 1400–1409
    #   Subchapter II (All children):       §§ 1411–1419  (no 1410, no 1420–1430)
    #   Subchapter III (Infants/toddlers):  §§ 1431–1444
    #   Subchapter IV (National activities): §§ 1450–1451, 1461–1467, 1470–1474, 1481–1482
    ('20', '33'): [
        '1400','1401','1402','1403','1404','1405','1406','1407','1408','1409',
        '1411','1412','1413','1414','1415','1416','1417','1418','1419',
        '1431','1432','1433','1434','1435','1436','1437','1438','1439',
        '1440','1441','1442','1443','1444',
        '1450','1451',
        '1461','1462','1463','1464','1465','1466',
        '1470','1471','1472','1473','1474',
        '1481','1482',
    ],

    # ── Title 34 USC — Crime Control and Law Enforcement ─────────────────────
    # Title 34 was codified in 2018 from Public Law 113-235.
    # Cornell LII chapter numbers are small integers (1, 10, 12, 13, …),
    # NOT in the 400s.  Section numbers in each chapter are in the 10000-60000 range.
    # Below are the most relevant chapters for law enforcement + education contexts.

    # Chapter 1 — Law Enforcement and Administration
    # Includes Byrne JAG grants (§§ 10151-10156), DNA analysis (§§ 10601+)
    ('34', '1'): [
        '10101','10102','10111','10121','10122','10123',
        '10151','10152','10153','10154','10155','10156','10157','10158',
        '10161','10162','10171','10172',
        '10181','10182','10191','10192','10193',
        '10201','10211','10221','10222','10223','10224',
        '10231','10232','10241','10242','10243',
        '10251','10261','10271','10272','10273',
        '10281','10291','10301','10302','10303','10304','10305','10306',
        '10311',
    ],

    # Chapter 10 — Juvenile Justice and Delinquency Prevention (§§ 11101-11322)
    ('34', '10'): [
        '11101','11102','11103','11104','11105','11106','11107','11108','11109',
        '11111','11112','11113','11114','11115','11116','11117','11118','11119',
        '11121','11122','11123','11124','11125','11126','11127','11128','11129',
        '11131','11132','11133','11134','11141','11142','11143','11144',
        '11151','11161','11162','11163','11164','11171','11172','11173',
        '11181','11182','11183','11184','11185','11186','11187','11188',
        '11191','11192','11193','11194',
        '11201','11211','11221','11222','11223','11224','11225',
        '11231','11241','11251','11261','11271','11281',
        '11291','11292','11293','11294','11295',
        '11301','11311','11312','11313','11321','11322',
    ],

    # Chapter 12 — Victims of Child Abuse (§§ 20301-20351)
    ('34', '12'): [
        '20301','20302','20303','20304','20305','20306',
        '20311','20312','20321','20322','20323','20324',
        '20331','20332','20333','20334','20335','20341','20351',
    ],

    # Chapter 12D — Violence Against Women (VAWA) — §§ 12291-12631
    # VAWA is a subchapter of Chapter 12; on Cornell LII it may be at chapter-12D
    # or accessible via the section numbers directly.
    ('34', '12D'): [
        '12291','12292','12294','12295',
        '12311','12312','12321','12322','12323','12324','12325','12326',
        '12331','12341','12351','12361','12371','12372',
        '12381','12391','12401','12402','12403','12404','12405',
        '12411','12412','12413','12414','12415','12421','12431',
        '12441','12442','12443','12444','12445','12451',
        '12461','12463','12464','12465',
        '12471','12472','12473','12474','12475','12476',
        '12481','12482','12491',
        '12501','12511','12512','12513','12514','12521',
        '12531','12541','12551','12561','12571',
        '12581','12582','12583','12584','12585','12586','12587',
        '12601','12602','12603','12611','12621','12631',
    ],

    # Chapter 13 — Violence Against Women Act — additional provisions
    # Includes campus sexual violence, stalking, dating violence programs
    ('34', '13'): [
        '20121','20122','20123','20124','20125','20126',
        '20131','20141','20151','20161','20162',
        '20171','20181','20191',
    ],

    # Chapter 19 — Victims of Crime (§§ 20101-20111)
    # Crime Victims Fund — affects law enforcement response + victim notification
    ('34', '19'): [
        '20101','20102','20103','20104','20105','20106',
        '20107','20108','20109','20110','20111',
    ],

    # Chapter 46 — Crime Victims Rights (§§ 60501-60506)
    ('34', '46'): [
        '60501','60502','60503','60504','60505','60506',
    ],
}


def usc_list_chapters(title_num: str) -> list[dict]:
    """
    Discover chapter numbers for a USC title from the Cornell LII title page.
    Returns [{num, name}] — num is the chapter designator (e.g. '33', '12D').
    Handles both flat (/uscode/text/20/chapter-33) and subtitle-layer
    (/uscode/text/34/subtitle-I/chapter-1) URL structures.
    """
    url = f'{USC_BASE}/{title_num}'
    html = fetch(url)
    if not html:
        return []
    soup = BeautifulSoup(html, 'html.parser')

    flat_pattern = re.compile(
        r'^/uscode/text/' + re.escape(title_num) + r'/chapter-([A-Z0-9]+)$', re.I
    )
    subtitle_chapter_pattern = re.compile(
        r'^/uscode/text/' + re.escape(title_num) + r'/subtitle-[^/]+/chapter-([A-Z0-9]+)$', re.I
    )
    subtitle_link_pattern = re.compile(
        r'^/uscode/text/' + re.escape(title_num) + r'/subtitle-[^/]+$', re.I
    )

    seen: set[str] = set()
    chapters: list[dict] = []

    def _scan_soup(s: BeautifulSoup) -> None:
        for a in s.find_all('a', href=True):
            href = a['href']
            m = flat_pattern.match(href) or subtitle_chapter_pattern.match(href)
            if m:
                num = m.group(1)
                if num not in seen:
                    seen.add(num)
                    name = a.get_text(' ', strip=True)
                    name = re.sub(r'^CHAPTER\s+\S+\s*[—\-]\s*', '', name, flags=re.I).strip()
                    chapters.append({
                        'num': num, 'name': name,
                        'url': 'https://www.law.cornell.edu' + href,
                    })

    _scan_soup(soup)

    # If no chapters found directly, follow subtitle links and scan those pages
    if not chapters:
        subtitle_hrefs = [
            a['href'] for a in soup.find_all('a', href=subtitle_link_pattern)
        ]
        for sub_href in subtitle_hrefs[:12]:  # safety cap
            sub_html = fetch('https://www.law.cornell.edu' + sub_href)
            if sub_html:
                _scan_soup(BeautifulSoup(sub_html, 'html.parser'))

    return chapters


def usc_list_sections(title_num: str, chapter_num: str,
                       chapter_url: str | None = None) -> list[str]:
    """Return section numbers for a USC title/chapter from Cornell LII."""
    url = chapter_url or f'{USC_BASE}/{title_num}/chapter-{chapter_num}'
    html = fetch(url)
    sections: list[str] = []

    if html and 'Not Found' not in html[:500]:
        soup = BeautifulSoup(html, 'html.parser')
        pattern = re.compile(r'^/uscode/text/' + re.escape(title_num) + r'/(\d+\w*)$', re.I)
        seen: set[str] = set()
        for a in soup.find_all('a', href=pattern):
            m = pattern.search(a['href'])
            if m:
                num = m.group(1)
                if num not in seen:
                    seen.add(num)
                    sections.append(num)

    if not sections:
        key = (title_num, chapter_num)
        if key in USC_CHAPTER_SECTIONS:
            print(f'  (Using known section range for USC Title {title_num} Chapter {chapter_num})')
            sections = USC_CHAPTER_SECTIONS[key]

    return sections


def usc_fetch_section(title_num: str, section_num: str,
                       title_name: str, chapter_num: str, chapter_name: str) -> dict | None:
    """Fetch one USC section from Cornell LII."""
    url = f'{USC_BASE}/{title_num}/{section_num}'
    html = fetch(url)
    if not html:
        return None
    soup = BeautifulSoup(html, 'html.parser')

    heading = ''
    for selector in ('h1.lii-title', 'h2.usc-title-head', '.lii-heading h2',
                     '#main-content h1', 'h1', 'h2', 'h3'):
        h = soup.select_one(selector)
        if h:
            heading = h.get_text(' ', strip=True)
            # Strip "§ 1415" prefix
            heading = re.sub(r'^§\s*\d+\w*\.?\s*', '', heading).strip()
            # Strip "20 U.S. Code § 1415 - " or "20 U.S.C. § 1415 - " prefix
            heading = re.sub(
                r'^\d+\s+U\.S\.(?:\s+Code|C\.?)\s*§\s*\d+\w*\.?\s*[-–—]\s*',
                '', heading, flags=re.I).strip()
            if heading:
                break

    content = ''
    for selector in ('#main-text', '.primary-content', '#primary-content',
                     '#main-content .col-sm-9', '#content .col-sm-9',
                     'article.lii-content', '#main-content', 'article', 'main'):
        div = soup.select_one(selector)
        if div:
            for nav in div.find_all(['nav', 'aside']):
                nav.decompose()
            content = div.get_text('\n', strip=True)
            if len(content) >= 30:
                break

    # Strip Cornell LII page-chrome that bleeds into text when broad selectors match
    content = re.sub(r'Quick search by citation:.*?Go!', '', content, flags=re.S | re.I)
    content = re.sub(r'U\.S\. Code\s+Notes\s+Authorities.*?(?=\n|$)', '', content, flags=re.I)
    content = re.sub(r'\bprev\s*\|\s*next\b', '', content, flags=re.I)
    content = re.sub(r'^\s*Authorities \(CFR\).*$', '', content, flags=re.M | re.I)
    content = re.sub(r'\s{3,}', '\n', content).strip()
    if not content or len(content) < 30:
        return None

    section_id = f'{title_num} USC § {section_num}'
    if not heading:
        heading = _heading_from_content(section_id, content)

    return {
        'corpus':          'usc',
        'title_num':       title_num,
        'title_name':      title_name,
        'chapter_num':     chapter_num,
        'chapter_name':    chapter_name,
        'section_num':     section_num,
        'section_id':      section_id,
        'section_heading': heading,
        'content':         content,
        'source_url':      url,
    }


def crawl_usc(filter_titles: list[str] | None = None,
              filter_chapters: list[str] | None = None) -> Generator[dict, None, None]:
    """
    Crawl USC sections from Cornell LII.
    --titles required (e.g. --titles 20).
    --chapters optional: if omitted, discovers chapters from the Cornell LII title page.
    Example: --corpus usc --titles 20 --chapters 33
    To see available chapters without ingesting: --dry-run (no --chapters)
    """
    if not filter_titles:
        print('ERROR: --titles required for USC  (e.g. --titles 20 --chapters 33)',
              file=sys.stderr)
        return

    for title_num in filter_titles:
        title_name = f'USC Title {title_num}'

        # Discover chapters if none specified
        if not filter_chapters:
            print(f'\n  Discovering chapters for USC Title {title_num}...')
            discovered = usc_list_chapters(title_num)
            if not discovered:
                print(f'  ERROR: could not discover chapters for USC Title {title_num}.',
                      file=sys.stderr)
                print(f'  Check https://www.law.cornell.edu/uscode/text/{title_num} '
                      'and specify --chapters explicitly.', file=sys.stderr)
                continue
            print(f'  Found {len(discovered)} chapters:')
            for c in discovered:
                in_known = '(known sections)' if (title_num, c['num']) in USC_CHAPTER_SECTIONS else ''
                print(f'    Chapter {c["num"]:<6} {c["name"]} {in_known}')
            chapters_to_crawl = [
                {'num': c['num'], 'name': c['name'], 'url': c.get('url')}
                for c in discovered
            ]
        else:
            chapters_to_crawl = [{'num': n, 'name': f'Chapter {n}'} for n in filter_chapters]

        for chap in chapters_to_crawl:
            chap_num  = chap['num']
            chap_name = chap['name']
            chap_url  = chap.get('url')
            print(f'\n  USC Title {title_num} Chapter {chap_num} — {chap_name}')
            sections = usc_list_sections(title_num, chap_num, chap_url)
            if not sections:
                print(f'    0 sections — chapter may not exist on Cornell LII. '
                      f'Check https://www.law.cornell.edu/uscode/text/{title_num}/chapter-{chap_num}')
                continue
            print(f'    {len(sections)} sections')
            for sec_num in sections:
                section = usc_fetch_section(title_num, sec_num, title_name,
                                            chap_num, chap_name)
                if section:
                    yield section


# ── PMC crawler (local Pasco Municipal Code HTML export) ─────────────────────

def _pmc_normalize(text: str) -> str:
    text = text.replace('\xa0', ' ')
    text = re.sub(r'[ \t]+', ' ', text)
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()


def _pmc_table_to_text(table: Tag) -> str:
    """Render HTML tables into compact plain text so structure survives embedding."""
    lines: list[str] = []

    caption = table.find('caption')
    if caption:
        cap_text = _pmc_normalize(caption.get_text(' ', strip=True))
        if cap_text:
            lines.append(f'Table: {cap_text}')

    for tr in table.find_all('tr'):
        cells = tr.find_all(['th', 'td'])
        row = [_pmc_normalize(c.get_text(' ', strip=True)) for c in cells]
        row = [c for c in row if c]
        if row:
            lines.append(' | '.join(row))

    if not lines:
        return ''
    return '[TABLE]\n' + '\n'.join(lines) + '\n[/TABLE]'


def _pmc_extract_section_content(article: Tag) -> str:
    """
    Extract section text and preserve table content.
    Removes TOC/history scaffolding to reduce token waste.
    """
    node = BeautifulSoup(str(article), 'html.parser')

    # Remove boilerplate/chrome inside each section.
    for bad in node.select('.subsec-actions, .toc, .tocHeading, .historyLabel'):
        bad.decompose()
    for hist in node.select('.note.history'):
        hist.decompose()

    # Replace tables with compact text blocks.
    for table in node.find_all('table'):
        table_text = _pmc_table_to_text(table)
        if table_text:
            table.replace_with(NavigableString('\n' + table_text + '\n'))
        else:
            table.decompose()

    text = _pmc_normalize(node.get_text('\n', strip=True))
    # Drop duplicated numeric heading lines at the start, e.g. "1.01.010"
    text = re.sub(r'^\d+\.\d+\.\d+(?:\([^)]+\))?\s*', '', text).strip()
    return text


def crawl_pmc(filter_titles: list[str] | None = None,
              filter_chapters: list[str] | None = None,
              pmc_file: str | None = None) -> Generator[dict, None, None]:
    """
    Crawl PMC sections from a local HTML export.
    - --titles should be title numbers like 1,2,3,...,29
    - --chapters (optional) should be chapter numbers like 1.01,25.12
    """
    path = Path(pmc_file or PMC_DEFAULT_HTML)
    if not path.exists():
        print(f'ERROR: PMC HTML file not found: {path}', file=sys.stderr)
        return

    print(f'Loading PMC HTML: {path}')
    html = path.read_text(encoding='utf-8', errors='replace')
    soup = BeautifulSoup(html, 'html.parser')

    title_articles = soup.select('article.type-Title')
    if not title_articles:
        print('ERROR: no PMC titles found in HTML. Verify the export file.', file=sys.stderr)
        return

    title_filter = set(filter_titles or [])
    chapter_filter = set(filter_chapters or [])
    section_count = 0
    title_count = 0

    for t in title_articles:
        title_num_raw = ''
        num_el = t.select_one('header .num')
        if num_el:
            m = re.search(r'Title\s+(\d+)', num_el.get_text(' ', strip=True), re.I)
            if m:
                title_num_raw = m.group(1)
        if not title_num_raw:
            t_id = t.get('id', '')
            m = re.match(r'PMC_(\d+)$', t_id)
            if m:
                title_num_raw = m.group(1)
        if not title_num_raw:
            continue

        title_num = str(int(title_num_raw))
        if title_filter and title_num not in title_filter:
            continue
        if 'node-reserved' in (t.get('class') or []):
            continue

        title_name = _pmc_normalize((t.select_one('header .name') or t).get_text(' ', strip=True))
        title_count += 1
        print(f'\n  PMC Title {title_num}: {title_name}')

        for section in t.select('article.type-Section'):
            sec_id_attr = section.get('id', '')
            sec_short = section.get('data-short-cite', '').strip()
            sec_match = re.match(r'(\d+\.\d+\.\d+(?:\([^)]+\))?)', sec_short) or \
                        re.match(r'PMC_(\d+\.\d+\.\d+(?:\([^)]+\))?)', sec_id_attr)
            if not sec_match:
                continue
            sec_num = sec_match.group(1)

            chap_match = re.match(r'(\d+\.\d+)\.', sec_num)
            chapter_num = chap_match.group(1) if chap_match else ''
            if chapter_filter and chapter_num not in chapter_filter:
                continue

            chapter_name = ''
            chapter = section.find_parent('article', class_=re.compile(r'\btype-Chapter\b'))
            if chapter:
                chapter_name = _pmc_normalize((chapter.select_one('header .name') or chapter).get_text(' ', strip=True))

            heading = _pmc_normalize((section.select_one('header .name') or section).get_text(' ', strip=True))
            content = _pmc_extract_section_content(section)
            if not content or len(content) < 20:
                continue

            section_count += 1
            yield {
                'corpus':          'pmc',
                'title_num':       title_num,
                'title_name':      title_name,
                'chapter_num':     chapter_num,
                'chapter_name':    chapter_name,
                'section_num':     sec_num,
                'section_id':      f'PMC {sec_num}',
                'section_heading': heading,
                'content':         content,
                'source_url':      f'file://{path.name}#PMC_{sec_num}',
            }

    print(f'\nPMC parse complete: {title_count} title(s), {section_count} section(s).')


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
    import time
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
        for attempt in range(5):
            try:
                sb.table('rcw_wac_chunks').upsert(rows, on_conflict='section_id,chunk_index').execute()
                break
            except Exception as e:
                msg = str(e)
                if 'rcw_wac_chunks_corpus_check' in msg and "'code': '23514'" in msg:
                    raise RuntimeError(
                        "Supabase rejected corpus value due to rcw_wac_chunks_corpus_check.\n"
                        "Your DB schema still only allows old corpus values.\n"
                        "Run the PMC migration SQL in rcw-wac/ingestion/schema.sql "
                        "(section: 'Migration v3: expand allowed corpora')."
                    ) from e
                if attempt == 4:
                    raise
                wait = 2 ** attempt   # 1s, 2s, 4s, 8s
                print(f'\n  Insert failed ({e}), retrying in {wait}s...')
                time.sleep(wait)
        inserted += len(rows)
        print(f'  Inserted {inserted}/{len(chunks)} rows...', end='\r', flush=True)
        time.sleep(0.5)  # throttle IO — free tier has a daily Disk IO budget
    print()
    return inserted


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    global CRAWL_DELAY_SEC   # must be declared before first use in this function

    parser = argparse.ArgumentParser(
        description='Scrape WA Legislature website → embed → store in Supabase.\n'
                    'Run once to build the database; queries go to Supabase, not leg.wa.gov.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('--corpus', required=True, choices=['rcw', 'wac', 'cfr', 'usc', 'pmc'],
                        help='Which code to crawl  (pmc = Pasco Municipal Code local HTML)')
    parser.add_argument('--titles', metavar='28A,42.56,180',
                        help='Comma-separated title numbers to limit the crawl. Omit for full corpus.')
    parser.add_argument('--chapters', metavar='392-172A,28A.400',
                        help='Comma-separated chapter numbers (title auto-derived if --titles omitted). '
                             'Example: --chapters 392-172A  ingests only WAC special ed rules.')
    parser.add_argument('--pmc-file', default=PMC_DEFAULT_HTML,
                        help='Path to Pasco-Municipal-Code.html for --corpus pmc '
                             f'(default: {PMC_DEFAULT_HTML})')
    parser.add_argument('--dry-run', action='store_true',
                        help='Crawl and parse without embedding or inserting. '
                             'Use this first to verify selectors are working.')
    parser.add_argument('--clear', action='store_true',
                        help='Delete existing DB rows for these titles before re-ingesting '
                             '(useful after a law update)')
    parser.add_argument('--delay', type=float, default=CRAWL_DELAY_SEC,
                        help=f'Seconds between HTTP requests (default {CRAWL_DELAY_SEC})')
    args = parser.parse_args()

    CRAWL_DELAY_SEC = args.delay

    filter_titles   = [t.strip() for t in args.titles.split(',')]   if args.titles   else None
    filter_chapters = [c.strip() for c in args.chapters.split(',')] if args.chapters else None

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

    # ── Clear existing rows upfront (before crawl) ────────────────────────────
    if args.clear and not args.dry_run:
        titles_to_clear = filter_titles or []
        if titles_to_clear:
            for t in titles_to_clear:
                sb.table('rcw_wac_chunks').delete() \
                  .eq('corpus', args.corpus).eq('title_num', t).execute()
            print(f'Cleared existing {args.corpus.upper()} rows for titles: {titles_to_clear}')
        else:
            sb.table('rcw_wac_chunks').delete().eq('corpus', args.corpus).execute()
            print(f'Cleared all existing {args.corpus.upper()} rows.')

    # ── Crawl ────────────────────────────────────────────────────────────────
    print(f'Crawling {args.corpus.upper()}' +
          (f' titles: {filter_titles}' if filter_titles else ' (full corpus)'))
    if args.dry_run:
        print('DRY RUN — no embedding or DB writes.')

    crawler = {
        'rcw': crawl_rcw,
        'wac': crawl_wac,
        'cfr': crawl_cfr,
        'usc': crawl_usc,
        'pmc': lambda titles, chapters: crawl_pmc(titles, chapters, args.pmc_file),
    }[args.corpus](filter_titles, filter_chapters)

    # Fetch existing hashes once — avoids a full-table SELECT on every flush
    # (that SELECT gets slower as the table grows to thousands of rows).
    existing_hashes: set[str] = fetch_existing_hashes(sb) if sb else set()

    all_chunks: list[dict] = []
    section_count = 0

    for section in crawler:
        section_count += 1
        chunks = build_chunks([section])
        all_chunks.extend(chunks)
        print(f'  {section["section_id"]} ({len(chunks)} chunk(s), {section_count} sections so far)',
              end='\r', flush=True)

        # Stream inserts in small batches to stay under Supabase statement timeout.
        if not args.dry_run and len(all_chunks) >= 200:
            new_hashes = _flush_chunks(all_chunks, oa, sb, existing_hashes)
            existing_hashes.update(new_hashes)
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
        _flush_chunks(all_chunks, oa, sb, existing_hashes)

    print('\nIngestion complete.')
    if sb:
        try:
            stats = sb.rpc('rcw_wac_stats', {}).execute()
            if stats.data:
                d = stats.data
                print(f'  Corpus totals: '
                      f'{d.get("pmc_chunks",0):,} PMC  '
                      f'{d.get("rcw_chunks",0):,} RCW  '
                      f'{d.get("wac_chunks",0):,} WAC  '
                      f'{d.get("usc_chunks",0):,} USC  '
                      f'{d.get("cfr_chunks",0):,} CFR  '
                      f'({d.get("total_chunks",0):,} total)')
        except Exception:
            print('  (stats query timed out — ingestion data was written successfully)')


def _flush_chunks(chunks: list[dict], oa, sb, existing_hashes: set[str]) -> set[str]:
    """Embed and upsert new chunks. Returns hashes of chunks that were inserted."""
    new_chunks = [c for c in chunks if c['content_hash'] not in existing_hashes]
    if not new_chunks:
        return set()

    print(f'\nEmbedding {len(new_chunks)} chunks...')
    embeddings = embed_texts(oa, [c['content'] for c in new_chunks])
    insert_chunks(sb, new_chunks, embeddings)
    return {c['content_hash'] for c in new_chunks}


if __name__ == '__main__':
    main()

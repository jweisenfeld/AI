#!/usr/bin/env python3
"""
RCW/WAC Ingestion Pipeline
Downloads WA Legislature XML, chunks sections, embeds, stores in Supabase.

Usage:
    python ingest.py rcw_title28A.xml --corpus rcw
    python ingest.py wac_title180.xml --corpus wac
    python ingest.py *.xml --corpus rcw --dry-run
    python ingest.py rcw_title28A.xml --corpus rcw --clear  # re-ingest, replacing existing

WA Legislature XML downloads:
    RCW: https://apps.leg.wa.gov/rcw/    (use the Download link per title)
    WAC: https://apps.leg.wa.gov/wac/    (use the Download link per title)

Environment variables (can also use a .env file):
    OPENAI_API_KEY      — for text-embedding-3-small
    SUPABASE_URL        — https://your-project.supabase.co
    SUPABASE_ANON_KEY   — Supabase anon/service key
"""

import argparse
import hashlib
import os
import re
import sys
import time
from pathlib import Path

import tiktoken
from lxml import etree
from openai import OpenAI
from supabase import create_client

# ── Constants ─────────────────────────────────────────────────────────────────

EMBEDDING_MODEL  = 'text-embedding-3-small'
MAX_CHUNK_TOKENS = 800     # target max tokens per chunk
OVERLAP_TOKENS   = 100     # overlap between consecutive chunks in a long section
EMBED_BATCH_SIZE = 100     # OpenAI embeddings per API call
INSERT_BATCH     = 250     # rows per Supabase INSERT

ENC = tiktoken.get_encoding('cl100k_base')


# ── Token utilities ───────────────────────────────────────────────────────────

def count_tokens(text: str) -> int:
    return len(ENC.encode(text))


def chunk_text(text: str, max_tokens: int = MAX_CHUNK_TOKENS,
               overlap: int = OVERLAP_TOKENS) -> list[str]:
    """Split text into overlapping token windows. Returns list of decoded strings."""
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


# ── XML parsing helpers ───────────────────────────────────────────────────────

def _all_text(el) -> str:
    """Join all descendant text nodes, normalising whitespace."""
    return re.sub(r'\s+', ' ', ' '.join(el.itertext())).strip()


def _attr(el, *names: str) -> str:
    """Return first matching attribute value, or empty string."""
    for name in names:
        v = el.get(name, '')
        if v:
            return v
    return ''


# ── RCW parser ────────────────────────────────────────────────────────────────

def parse_rcw_xml(xml_path: str) -> list[dict]:
    """
    Parse a WA Legislature RCW XML file.

    The legislature distributes one XML file per title. The schema used
    at download time may vary slightly; this parser handles the most common
    variants by checking multiple element/attribute names.

    Returns a list of section dicts (not yet chunked or embedded).
    """
    tree = etree.parse(xml_path)
    root = tree.getroot()

    # Determine root structure: the file root may itself be the title element,
    # or titles may be nested inside a wrapper element.
    title_els = list(root.iter('rcw-title'))
    if not title_els:
        # Some exports nest titles under <rcw> or <titles>
        title_els = [root] if root.tag == 'rcw-title' else []

    if not title_els:
        print(f"  WARNING: no <rcw-title> elements found in {xml_path}", file=sys.stderr)
        return []

    sections = []
    for title_el in title_els:
        title_num  = _attr(title_el, 'id', 'num', 'number')
        title_name = _attr(title_el, 'name', 'shortname', 'title')

        for chap_el in title_el.iter('rcw-chapter'):
            chap_num  = _attr(chap_el, 'id', 'num', 'number')
            chap_name = _attr(chap_el, 'name', 'shortname', 'title')

            for sec_el in chap_el.iter('rcw-section'):
                # Section number: prefer attribute, fall back to <rcw-num> child
                sec_id = _attr(sec_el, 'id', 'num', 'number')
                if not sec_id:
                    num_el = sec_el.find('rcw-num')
                    if num_el is not None:
                        sec_id = (num_el.text or '').strip()
                if not sec_id:
                    continue

                sec_num = sec_id.rsplit('.', 1)[-1] if '.' in sec_id else sec_id

                # Heading
                heading = ''
                for tag in ('heading', 'rcw-heading', 'shortname'):
                    h_el = sec_el.find(tag)
                    if h_el is not None and h_el.text:
                        heading = h_el.text.strip()
                        break
                if not heading:
                    heading = _attr(sec_el, 'name', 'shortname')

                # Body text — prefer <rcw-body>, fall back to whole section
                body_el = sec_el.find('rcw-body') or sec_el
                content = _all_text(body_el)

                # Strip any leftover section-number prefix that lxml extracted
                # (the rcw-num child text ends up in the body iterator)
                content = re.sub(r'^RCW\s+[\d\.]+\s*', '', content).strip()
                content = re.sub(r'^[\d\.]+\s+', '', content).strip()

                if not content or len(content) < 30:
                    continue

                sections.append({
                    'corpus':          'rcw',
                    'title_num':       title_num,
                    'title_name':      title_name or f'Title {title_num}',
                    'chapter_num':     chap_num,
                    'chapter_name':    chap_name or f'Chapter {chap_num}',
                    'section_num':     sec_num,
                    'section_id':      f'RCW {sec_id}',
                    'section_heading': heading,
                    'content':         content,
                    'source_url':      f'https://app.leg.wa.gov/rcw/default.aspx?cite={sec_id}',
                })

    return sections


# ── WAC parser ────────────────────────────────────────────────────────────────

def parse_wac_xml(xml_path: str) -> list[dict]:
    """
    Parse a WA Legislature WAC XML file.
    Mirrors the RCW parser with WAC-specific element names.
    """
    tree = etree.parse(xml_path)
    root = tree.getroot()

    title_els = list(root.iter('wac-title'))
    if not title_els and root.tag == 'wac-title':
        title_els = [root]

    if not title_els:
        print(f"  WARNING: no <wac-title> elements found in {xml_path}", file=sys.stderr)
        return []

    sections = []
    for title_el in title_els:
        title_num  = _attr(title_el, 'id', 'num', 'number')
        title_name = _attr(title_el, 'name', 'shortname', 'title')

        for chap_el in title_el.iter('wac-chapter'):
            chap_num  = _attr(chap_el, 'id', 'num', 'number')
            chap_name = _attr(chap_el, 'name', 'shortname', 'title')

            for sec_el in chap_el.iter('wac-section'):
                sec_id = _attr(sec_el, 'id', 'num', 'number')
                if not sec_id:
                    num_el = sec_el.find('wac-num')
                    if num_el is not None:
                        sec_id = (num_el.text or '').strip()
                if not sec_id:
                    continue

                sec_num = sec_id.rsplit('-', 1)[-1] if '-' in sec_id else sec_id

                heading = ''
                for tag in ('heading', 'wac-heading', 'shortname'):
                    h_el = sec_el.find(tag)
                    if h_el is not None and h_el.text:
                        heading = h_el.text.strip()
                        break
                if not heading:
                    heading = _attr(sec_el, 'name', 'shortname')

                body_el = sec_el.find('wac-body') or sec_el
                content = _all_text(body_el)
                content = re.sub(r'^WAC\s+[\d\-]+\s*', '', content).strip()

                if not content or len(content) < 30:
                    continue

                sections.append({
                    'corpus':          'wac',
                    'title_num':       title_num,
                    'title_name':      title_name or f'Title {title_num}',
                    'chapter_num':     chap_num,
                    'chapter_name':    chap_name or f'Chapter {chap_num}',
                    'section_num':     sec_num,
                    'section_id':      f'WAC {sec_id}',
                    'section_heading': heading,
                    'content':         content,
                    'source_url':      f'https://apps.leg.wa.gov/wac/default.aspx?cite={sec_id}',
                })

    return sections


# ── Chunking ──────────────────────────────────────────────────────────────────

def build_chunks(sections: list[dict]) -> list[dict]:
    """
    Convert parsed sections into ingestable chunk dicts.

    Every chunk's 'content' field is prefixed with the section citation
    and heading so that context is preserved even in multi-chunk sections.

    Returns list of chunk dicts ready for embedding + Supabase insert.
    """
    chunks = []
    for sec in sections:
        # Build the prefix that appears on every chunk of this section
        prefix = sec['section_id']
        if sec['section_heading']:
            prefix += f" — {sec['section_heading']}"
        prefix += '\n\n'

        body_chunks = chunk_text(sec['content'], MAX_CHUNK_TOKENS, OVERLAP_TOKENS)

        for i, body in enumerate(body_chunks):
            full_content = prefix + body
            content_hash = hashlib.sha256(full_content.encode()).hexdigest()[:32]
            chunks.append({
                'corpus':          sec['corpus'],
                'title_num':       sec['title_num'],
                'title_name':      sec['title_name'],
                'chapter_num':     sec['chapter_num'],
                'chapter_name':    sec['chapter_name'],
                'section_num':     sec['section_num'],
                'section_id':      sec['section_id'],
                'section_heading': sec['section_heading'],
                'chunk_index':     i,
                'content':         full_content,
                'token_count':     count_tokens(full_content),
                'source_url':      sec['source_url'],
                'content_hash':    content_hash,
            })

    return chunks


# ── Embedding ─────────────────────────────────────────────────────────────────

def embed_texts(client: OpenAI, texts: list[str]) -> list[list[float]]:
    """Embed texts in batches. Returns list of 1536-dim vectors."""
    embeddings = []
    total = len(texts)
    for i in range(0, total, EMBED_BATCH_SIZE):
        batch = texts[i:i + EMBED_BATCH_SIZE]
        resp  = client.embeddings.create(input=batch, model=EMBEDDING_MODEL)
        embeddings.extend(item.embedding for item in resp.data)
        done = min(i + EMBED_BATCH_SIZE, total)
        print(f"  Embedded {done}/{total}...", end='\r', flush=True)
        if done < total:
            time.sleep(0.05)   # gentle rate-limit pause
    print()
    return embeddings


# ── Supabase insert ───────────────────────────────────────────────────────────

def fetch_existing_hashes(sb) -> set[str]:
    """Return the set of content_hash values already in rcw_wac_chunks."""
    result = sb.table('rcw_wac_chunks').select('content_hash').execute()
    return {r['content_hash'] for r in (result.data or [])}


def insert_chunks(sb, chunks: list[dict], embeddings: list[list[float]]) -> int:
    """Insert chunk rows in batches. Returns count of rows inserted."""
    inserted = 0
    for i in range(0, len(chunks), INSERT_BATCH):
        batch      = chunks[i:i + INSERT_BATCH]
        batch_embs = embeddings[i:i + INSERT_BATCH]
        rows = [
            {
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
            }
            for c, emb in zip(batch, batch_embs)
        ]
        sb.table('rcw_wac_chunks').insert(rows).execute()
        inserted += len(rows)
        print(f"  Inserted {inserted}/{len(chunks)} rows...", end='\r', flush=True)
    print()
    return inserted


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='Ingest WA Legislature XML into Supabase rcw_wac_chunks table.'
    )
    parser.add_argument('xml_files', nargs='+', metavar='XML_FILE',
                        help='One or more RCW/WAC XML files downloaded from apps.leg.wa.gov')
    parser.add_argument('--corpus', required=True, choices=['rcw', 'wac'],
                        help='Corpus type for all files in this run')
    parser.add_argument('--dry-run', action='store_true',
                        help='Parse and chunk without embedding or inserting')
    parser.add_argument('--clear', action='store_true',
                        help='Delete existing rows for the titles in these files before re-ingesting')
    args = parser.parse_args()

    # ── Environment variables ─────────────────────────────────────────────────
    openai_key    = os.environ.get('OPENAI_API_KEY', '').strip()
    supabase_url  = os.environ.get('SUPABASE_URL', '').strip()
    supabase_key  = os.environ.get('SUPABASE_ANON_KEY', '').strip()

    if not args.dry_run:
        if not openai_key:
            sys.exit('ERROR: OPENAI_API_KEY not set.')
        if not supabase_url or not supabase_key:
            sys.exit('ERROR: SUPABASE_URL and SUPABASE_ANON_KEY must be set.')

    openai_client = OpenAI(api_key=openai_key) if not args.dry_run else None
    sb            = create_client(supabase_url, supabase_key) if not args.dry_run else None

    # ── Process each XML file ─────────────────────────────────────────────────
    all_chunks: list[dict] = []

    for xml_path in args.xml_files:
        path = Path(xml_path)
        if not path.exists():
            print(f"SKIP: {xml_path} not found", file=sys.stderr)
            continue

        print(f"\nParsing {path.name} ...")
        if args.corpus == 'rcw':
            sections = parse_rcw_xml(str(path))
        else:
            sections = parse_wac_xml(str(path))

        print(f"  {len(sections)} sections parsed")

        chunks = build_chunks(sections)
        print(f"  {len(chunks)} chunks after splitting")
        all_chunks.extend(chunks)

    if not all_chunks:
        print('No chunks produced. Check XML structure.')
        return

    print(f'\nTotal: {len(all_chunks)} chunks across all files')

    if args.dry_run:
        # Show a sample
        for c in all_chunks[:3]:
            print(f"\n--- {c['section_id']} (chunk {c['chunk_index']}, {c['token_count']} tok) ---")
            print(c['content'][:300] + '...' if len(c['content']) > 300 else c['content'])
        print(f'\nDry run complete. Would ingest {len(all_chunks)} chunks.')
        return

    # ── Clear old rows if requested ───────────────────────────────────────────
    if args.clear:
        title_nums = list({c['title_num'] for c in all_chunks})
        print(f'\nClearing existing rows for titles: {title_nums}')
        for t in title_nums:
            sb.table('rcw_wac_chunks') \
              .delete() \
              .eq('corpus', args.corpus) \
              .eq('title_num', t) \
              .execute()
        existing_hashes: set[str] = set()
    else:
        print('\nFetching existing content hashes for dedup ...')
        existing_hashes = fetch_existing_hashes(sb)
        print(f'  {len(existing_hashes)} existing chunks in DB')

    # ── Deduplicate ───────────────────────────────────────────────────────────
    new_chunks = [c for c in all_chunks if c['content_hash'] not in existing_hashes]
    skipped    = len(all_chunks) - len(new_chunks)
    print(f'\n{len(new_chunks)} new chunks to ingest ({skipped} duplicates skipped)')

    if not new_chunks:
        print('Nothing to ingest.')
        return

    # ── Embed ─────────────────────────────────────────────────────────────────
    texts = [c['content'] for c in new_chunks]
    print(f'\nEmbedding {len(texts)} chunks with {EMBEDDING_MODEL} ...')
    embeddings = embed_texts(openai_client, texts)

    # ── Insert ────────────────────────────────────────────────────────────────
    print(f'\nInserting into Supabase ...')
    n = insert_chunks(sb, new_chunks, embeddings)
    print(f'\nDone. {n} chunks ingested.')

    # ── Summary ───────────────────────────────────────────────────────────────
    by_title: dict[str, int] = {}
    for c in new_chunks:
        by_title[c['title_num']] = by_title.get(c['title_num'], 0) + 1
    print('\nChunks by title:')
    for t, n in sorted(by_title.items()):
        print(f'  {args.corpus.upper()} Title {t}: {n}')


if __name__ == '__main__':
    main()

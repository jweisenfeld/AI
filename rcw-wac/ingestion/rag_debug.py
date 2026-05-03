#!/usr/bin/env python3
"""
RAG Quality Debugger
====================
Runs a query through the same embedding + Supabase search pipeline as the
live chatbot, then prints similarity scores so you can see exactly why
certain chunks did or didn't surface.

Usage:
    # Search with default settings (same as chatbot default: 16 results, min_sim 0.25)
    python rag_debug.py "How many years of experience does a teacher need to get assigned a student teacher?"

    # Filter to psd1 only
    python rag_debug.py "student teacher requirements" --corpus psd1

    # Lower similarity floor to surface near-misses
    python rag_debug.py "student teacher requirements" --min-sim 0.1 --limit 20

    # Look up a specific section (is it even in the DB?)
    python rag_debug.py --lookup PSD1-5440P

    # Look up all chunks matching a pattern
    python rag_debug.py --lookup "5440"
"""

import argparse
import json
import os
import sys
import urllib.request
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).parent / '.env', override=True)

OPENAI_API_KEY    = os.environ.get('OPENAI_API_KEY', '')
SUPABASE_URL      = os.environ.get('SUPABASE_URL', '').rstrip('/')
SUPABASE_SVC_KEY  = os.environ.get('SUPABASE_SERVICE_KEY', '')
EMBEDDING_MODEL   = 'text-embedding-3-small'


def embed(text: str) -> list[float]:
    payload = json.dumps({'input': text, 'model': EMBEDDING_MODEL}).encode()
    req = urllib.request.Request(
        'https://api.openai.com/v1/embeddings',
        data=payload,
        headers={
            'Content-Type': 'application/json',
            'Authorization': f'Bearer {OPENAI_API_KEY}',
        },
    )
    with urllib.request.urlopen(req, timeout=20) as resp:
        data = json.loads(resp.read())
    return data['data'][0]['embedding']


def search(embedding: list[float], query_text: str, corpus: str | None,
           limit: int, min_sim: float) -> list[dict]:
    params: dict = {
        'query_embedding': embedding,
        'match_count':     limit,
        'min_similarity':  min_sim,
        'query_text':      query_text,
    }
    if corpus:
        params['filter_corpus'] = corpus

    payload = json.dumps(params).encode()
    req = urllib.request.Request(
        f'{SUPABASE_URL}/rest/v1/rpc/search_rcw_wac',
        data=payload,
        headers={
            'Content-Type':  'application/json',
            'apikey':        SUPABASE_SVC_KEY,
            'Authorization': f'Bearer {SUPABASE_SVC_KEY}',
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read())


def lookup(pattern: str) -> list[dict]:
    """Direct DB lookup — bypasses embeddings entirely. Shows what's in Supabase."""
    # Use ilike for flexible pattern match
    encoded = urllib.parse.quote(f'%{pattern}%')
    url = (f'{SUPABASE_URL}/rest/v1/rcw_wac_chunks'
           f'?select=id,corpus,section_id,section_heading,chunk_index,token_count,content'
           f'&section_id=ilike.{encoded}'
           f'&order=section_id,chunk_index'
           f'&limit=20')
    req = urllib.request.Request(
        url,
        headers={
            'apikey':        SUPABASE_SVC_KEY,
            'Authorization': f'Bearer {SUPABASE_SVC_KEY}',
        },
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read())


def main():
    import urllib.parse  # needed by lookup()

    ap = argparse.ArgumentParser(description='RAG quality debugger')
    ap.add_argument('query',       nargs='?', help='Natural language query to test')
    ap.add_argument('--corpus',    help='Filter corpus (psd1|rcw|wac|usc|cfr|state|federal|local)')
    ap.add_argument('--limit',     type=int, default=16, help='Max results (default 16)')
    ap.add_argument('--min-sim',   type=float, default=0.25, dest='min_sim',
                    help='Min cosine similarity (default 0.25, try 0.1 for near-misses)')
    ap.add_argument('--lookup',    metavar='PATTERN',
                    help='Direct DB lookup by section_id pattern (no embedding needed)')
    ap.add_argument('--content',   action='store_true',
                    help='Show chunk content (truncated to 400 chars)')
    args = ap.parse_args()

    if not SUPABASE_URL or not SUPABASE_SVC_KEY:
        sys.exit('ERROR: SUPABASE_URL and SUPABASE_SERVICE_KEY must be set in .env')

    # ── Direct lookup mode ────────────────────────────────────────────────────
    if args.lookup:
        import urllib.parse
        print(f'\nLooking up section_id LIKE "%{args.lookup}%" ...\n')
        rows = lookup(args.lookup)
        if not rows:
            print('  [no rows found — section was never ingested or has a different ID]')
            return
        for r in rows:
            heading = f" — {r['section_heading']}" if r.get('section_heading') else ''
            tokens  = f"  ({r['token_count']} tokens)" if r.get('token_count') else ''
            print(f"  [{r['corpus'].upper()}] {r['section_id']} chunk#{r['chunk_index']}{heading}{tokens}")
            if args.content:
                text = (r.get('content') or '')[:400]
                print(f"    {text!r}\n")
        print(f'\n{len(rows)} chunk(s) found.')
        return

    # ── Semantic search mode ──────────────────────────────────────────────────
    if not args.query:
        ap.print_help()
        sys.exit(1)

    if not OPENAI_API_KEY:
        sys.exit('ERROR: OPENAI_API_KEY must be set in .env')

    print(f'\nQuery : {args.query}')
    print(f'Corpus: {args.corpus or "all"}   limit={args.limit}   min_sim={args.min_sim}\n')

    print('Embedding query...', end=' ', flush=True)
    vec = embed(args.query)
    print('done.')

    print('Searching Supabase...', end=' ', flush=True)
    results = search(vec, args.query, args.corpus, args.limit, args.min_sim)
    print(f'{len(results)} results.\n')

    if not results:
        print('  [no results — try lowering --min-sim or removing --corpus filter]')
        return

    # ── Print ranked results ──────────────────────────────────────────────────
    print(f"{'Rank':<5} {'Sim%':<6} {'Corpus':<7} {'Section ID':<22} {'Heading'}")
    print('-' * 80)
    for i, r in enumerate(results, 1):
        sim     = r.get('similarity')
        sim_str = f"{sim*100:5.1f}%" if sim and sim > 0.01 else 'text  '
        corpus  = (r.get('corpus') or '').upper()
        sid     = r.get('section_id') or ''
        heading = (r.get('section_heading') or '')[:40]
        print(f"{i:<5} {sim_str:<6} {corpus:<7} {sid:<22} {heading}")
        if args.content:
            text = (r.get('content') or '')[:400]
            print(f"      {text!r}\n")

    # ── Highlight psd1 hits ───────────────────────────────────────────────────
    psd1_hits = [r for r in results if r.get('corpus') == 'psd1']
    print(f'\n  PSD1 chunks in results: {len(psd1_hits)} / {len(results)}')
    if psd1_hits:
        for r in psd1_hits:
            sim = r.get('similarity')
            sim_str = f"{sim*100:.1f}%" if sim and sim > 0.01 else 'text-only'
            print(f"    ✓ {r['section_id']} — {r.get('section_heading','')}  [{sim_str}]")


if __name__ == '__main__':
    main()

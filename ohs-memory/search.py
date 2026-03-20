"""
OHS Memory — Search & Chunk Comparison Tool
============================================
CLI tool for searching the OHS memory database.
The killer feature: side-by-side comparison of small vs large chunk results
for the same query, so you can SEE the difference in retrieval quality.

Usage:
    python search.py "how do we handle late work?"
    python search.py "economics assignments" --subject Economics --year 2025-26
    python search.py "phone policy" --type policy
    python search.py "unit 2 lesson plans" --compare    # show small vs large side by side
"""

import argparse
import os
import sys
import textwrap
from typing import Optional

# Force UTF-8 output on Windows (avoids cp1252 errors with box-drawing chars)
if sys.stdout.encoding != "utf-8":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv()

EMBEDDING_MODEL = "text-embedding-3-small"
_openai_client: OpenAI | None = None


def get_openai():
    global _openai_client
    if _openai_client is None:
        _openai_client = OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _openai_client


def embed_query(query: str) -> str:
    """Embed query text and return as pgvector literal string."""
    embedding = get_openai().embeddings.create(input=[query], model=EMBEDDING_MODEL).data[0].embedding
    return "[" + ",".join(f"{x:.8f}" for x in embedding) + "]"


def search(
    query: str,
    chunk_size: Optional[str] = None,   # 'small', 'large', or None (both)
    subject: Optional[str] = None,
    school_year: Optional[str] = None,
    doc_type: Optional[str] = None,
    teacher: Optional[str] = None,
    limit: int = 5,
    db_url: Optional[str] = None,
) -> list[dict]:
    """
    Semantic search over the OHS memory corpus.
    Returns ranked results with similarity scores and full metadata.
    """
    db_url = db_url or os.environ["DATABASE_URL"]
    embedding_str = embed_query(query)

    # Build WHERE clause dynamically
    conditions = []
    params = []

    if chunk_size:
        conditions.append("c.chunk_size = %s")
        params.append(chunk_size)
    if subject:
        conditions.append("d.subject ILIKE %s")
        params.append(f"%{subject}%")
    if school_year:
        conditions.append("d.school_year = %s")
        params.append(school_year)
    if doc_type:
        conditions.append("d.doc_type = %s")
        params.append(doc_type)
    if teacher:
        conditions.append("d.teacher ILIKE %s")
        params.append(f"%{teacher}%")

    where = ("WHERE " + " AND ".join(conditions)) if conditions else ""

    sql = f"""
        SELECT
            c.content,
            c.chunk_size,
            c.token_count,
            c.position,
            1 - (c.embedding <=> %s::vector)  AS similarity,
            d.original_filename,
            d.subject,
            d.school_year,
            d.teacher,
            d.doc_type,
            d.unit,
            d.ingested_at::text
        FROM chunks c
        JOIN documents d ON c.document_id = d.id
        {where}
        ORDER BY c.embedding <=> %s::vector
        LIMIT %s
    """

    # params order: embedding (for SELECT), filter conditions, embedding (for ORDER BY), limit
    all_params = [embedding_str] + params + [embedding_str, limit]

    conn = psycopg2.connect(db_url)
    cur = conn.cursor()
    try:
        cur.execute(sql, all_params)
        rows = cur.fetchall()
    finally:
        cur.close()
        conn.close()

    return [
        {
            "content":    row[0],
            "chunk_size": row[1],
            "tokens":     row[2],
            "position":   row[3],
            "similarity": round(float(row[4]), 3),
            "filename":   row[5],
            "subject":    row[6],
            "year":       row[7],
            "teacher":    row[8],
            "doc_type":   row[9],
            "unit":       row[10],
            "ingested":   row[11],
        }
        for row in rows
    ]


def list_documents(
    subject=None, school_year=None, doc_type=None, db_url=None
) -> list[dict]:
    """List all ingested documents with metadata."""
    db_url = db_url or os.environ["DATABASE_URL"]

    conditions = []
    params = []
    if subject:
        conditions.append("subject ILIKE %s")
        params.append(f"%{subject}%")
    if school_year:
        conditions.append("school_year = %s")
        params.append(school_year)
    if doc_type:
        conditions.append("doc_type = %s")
        params.append(doc_type)

    where = ("WHERE " + " AND ".join(conditions)) if conditions else ""

    sql = f"""
        SELECT
            original_filename, subject, school_year, teacher,
            doc_type, unit, ingested_at::text,
            (SELECT COUNT(*) FROM chunks WHERE document_id = d.id AND chunk_size = 'small') AS small_chunks,
            (SELECT COUNT(*) FROM chunks WHERE document_id = d.id AND chunk_size = 'large') AS large_chunks
        FROM documents d
        {where}
        ORDER BY ingested_at DESC
    """

    conn = psycopg2.connect(db_url)
    cur = conn.cursor()
    try:
        cur.execute(sql, params)
        rows = cur.fetchall()
    finally:
        cur.close()
        conn.close()

    return [
        {
            "filename":     row[0],
            "subject":      row[1],
            "year":         row[2],
            "teacher":      row[3],
            "doc_type":     row[4],
            "unit":         row[5],
            "ingested":     row[6],
            "small_chunks": row[7],
            "large_chunks": row[8],
        }
        for row in rows
    ]


# ── Display helpers ────────────────────────────────────────────────────────────

COLS = 100  # terminal width for wrapping

def divider(char="─", width=COLS):
    print(char * width)

def print_result(result: dict, rank: int, show_full: bool = False):
    """Pretty-print a single search result."""
    sim_bar = "█" * int(result["similarity"] * 20)
    sim_pct = f"{result['similarity']:.1%}"

    meta_parts = []
    if result["subject"]:   meta_parts.append(result["subject"])
    if result["year"]:      meta_parts.append(result["year"])
    if result["teacher"]:   meta_parts.append(result["teacher"])
    if result["doc_type"]:  meta_parts.append(f"[{result['doc_type']}]")
    if result["unit"]:      meta_parts.append(f"unit: {result['unit']}")
    meta_str = "  •  ".join(meta_parts) if meta_parts else "(no metadata)"

    print(f"\n  [{rank}] {sim_pct} {sim_bar}")
    print(f"  📄 {result['filename']}  ({result['tokens']} tokens, chunk #{result['position']})")
    print(f"  {meta_str}")
    print()

    # Show content (truncated unless --full)
    content = result["content"].strip()
    if not show_full and len(content) > 400:
        content = content[:400] + "..."

    for line in textwrap.wrap(content, width=COLS - 4):
        print(f"    {line}")


def print_compare(small_results: list[dict], large_results: list[dict], query: str):
    """
    Side-by-side comparison of small vs large chunk results.
    This is the tool for tuning your chunking strategy.
    """
    print("\n" + "═" * COLS)
    print(f"  CHUNK SIZE COMPARISON  |  Query: \"{query}\"")
    print("═" * COLS)

    n = max(len(small_results), len(large_results))
    for i in range(n):
        print(f"\n  ── Rank #{i+1} " + "─" * (COLS - 12))
        print()

        # Left: small
        print(f"  SMALL (~200 tokens)")
        if i < len(small_results):
            r = small_results[i]
            print(f"  Similarity: {r['similarity']:.1%}  |  {r['filename']}")
            content = r["content"].strip()[:300] + ("..." if len(r["content"]) > 300 else "")
            for line in textwrap.wrap(content, width=COLS - 4):
                print(f"    {line}")
        else:
            print("    (no result)")

        print()
        print(f"  LARGE (~900 tokens)")
        if i < len(large_results):
            r = large_results[i]
            print(f"  Similarity: {r['similarity']:.1%}  |  {r['filename']}")
            content = r["content"].strip()[:600] + ("..." if len(r["content"]) > 600 else "")
            for line in textwrap.wrap(content, width=COLS - 4):
                print(f"    {line}")
        else:
            print("    (no result)")

    print("\n" + "═" * COLS)
    print()
    print("  TAKEAWAYS TO LOOK FOR:")
    print("  • Small chunks: Does the precise answer appear? Or is it cut off mid-thought?")
    print("  • Large chunks: Does the extra context help? Or is it noise?")
    print("  • Similarity scores: Are they meaningfully different between sizes?")
    print("  • If small wins: your queries are specific; 200 tokens is right.")
    print("  • If large wins: your queries need context; 900 tokens is right.")
    print("  • If they return DIFFERENT documents: chunking is affecting which docs surface.")
    print()


# ── CLI Entry Point ────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Search the OHS organizational memory.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python search.py "how do we handle late work?"
  python search.py "economics unit 2 assignments" --subject Economics --year 2025-26
  python search.py "phone policy" --type policy --compare
  python search.py --list                          # show all ingested documents
  python search.py --list --subject Economics      # filter document list
        """,
    )
    parser.add_argument("query", nargs="?", help="Search query")
    parser.add_argument("--subject",  help="Filter by subject")
    parser.add_argument("--year",     help="Filter by school year (e.g. 2025-26)")
    parser.add_argument("--teacher",  help="Filter by teacher")
    parser.add_argument("--type",
        choices=["lesson_plan", "meeting_notes", "policy", "email", "other"],
        help="Filter by document type",
    )
    parser.add_argument("--size",
        choices=["small", "large"],
        help="Only search one chunk size (default: both)",
    )
    parser.add_argument("-n", "--limit", type=int, default=5,
        help="Number of results to return (default: 5)",
    )
    parser.add_argument("--compare", action="store_true",
        help="Show small vs large chunks side-by-side (the main tuning tool)",
    )
    parser.add_argument("--full", action="store_true",
        help="Show full chunk content (not truncated)",
    )
    parser.add_argument("--list", action="store_true",
        help="List all ingested documents",
    )
    args = parser.parse_args()

    # ── List mode ─────────────────────────────────────────────────────────────
    if args.list:
        docs = list_documents(
            subject=args.subject,
            school_year=args.year,
            doc_type=args.type,
        )
        if not docs:
            print("No documents found.")
            return

        print(f"\n{'─'*COLS}")
        print(f"  OHS Memory Archive — {len(docs)} document(s)")
        print(f"{'─'*COLS}\n")
        for d in docs:
            parts = [d["filename"]]
            if d["subject"]:  parts.append(d["subject"])
            if d["year"]:     parts.append(d["year"])
            if d["teacher"]:  parts.append(d["teacher"])
            if d["doc_type"]: parts.append(f"[{d['doc_type']}]")
            if d["unit"]:     parts.append(f"unit: {d['unit']}")
            print("  " + "  •  ".join(parts))
            print(f"    {d['small_chunks']} small chunks  |  {d['large_chunks']} large chunks  |  ingested {d['ingested'][:10]}")
            print()
        return

    # ── Search mode ───────────────────────────────────────────────────────────
    if not args.query:
        parser.print_help()
        return

    filter_kwargs = dict(
        subject=args.subject,
        school_year=args.year,
        doc_type=args.type,
        teacher=args.teacher,
        limit=args.limit,
    )

    if args.compare:
        # Run both searches in sequence and display side-by-side
        print(f"\n  Searching... (loading embeddings)")
        small = search(args.query, chunk_size="small", **filter_kwargs)
        large = search(args.query, chunk_size="large", **filter_kwargs)
        print_compare(small, large, args.query)

    else:
        # Standard search
        results = search(args.query, chunk_size=args.size, **filter_kwargs)

        size_label = f" ({args.size} chunks)" if args.size else " (all chunk sizes)"
        print(f"\n{'─'*COLS}")
        print(f"  Query: \"{args.query}\"{size_label}")
        print(f"  {len(results)} result(s)")
        print(f"{'─'*COLS}")

        if not results:
            print("\n  No results found. Try a different query or remove filters.\n")
            return

        for i, result in enumerate(results, 1):
            print_result(result, i, show_full=args.full)
            divider()

        print()


if __name__ == "__main__":
    main()

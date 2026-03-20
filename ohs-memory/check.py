"""
OHS Memory — System Health Check
==================================
Run this any time you want to verify the system is working end-to-end.
Tests every layer: database connection, document list, vector search,
and the Supabase RPC functions that the web page will call.

Usage:
    python check.py
    python -X utf8 check.py       (Windows — avoids encoding errors)
"""

import json
import os
import sys
from urllib.request import Request, urlopen
from urllib.error import URLError

import psycopg2
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv()

# Force UTF-8 on Windows
if sys.stdout.encoding and sys.stdout.encoding.lower() != "utf-8":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

PASS = "[PASS]"
FAIL = "[FAIL]"
WARN = "[WARN]"

results = []

def check(label, fn):
    """Run a check function, print result, record pass/fail."""
    try:
        detail = fn()
        print(f"  {PASS}  {label}")
        if detail:
            for line in str(detail).splitlines():
                print(f"          {line}")
        results.append((True, label))
    except Exception as e:
        print(f"  {FAIL}  {label}")
        print(f"          ERROR: {e}")
        results.append((False, label))


# ── Check 1: Environment variables ────────────────────────────────────────────
print("\n=== OHS Memory Health Check ===\n")
print("-- Environment --")

def check_env():
    missing = []
    for key in ["OPENAI_API_KEY", "DATABASE_URL", "SUPABASE_URL", "SUPABASE_ANON_KEY"]:
        if not os.environ.get(key):
            missing.append(key)
    if missing:
        raise ValueError(f"Missing: {', '.join(missing)}")
    anthropic_key = os.environ.get("ANTHROPIC_API_KEY")
    if not anthropic_key:
        return "ANTHROPIC_API_KEY not set — PDF ingestion will fail, but search is fine"
    return "All 5 environment variables present"

check("Environment variables loaded", check_env)


# ── Check 2: Direct database connection ───────────────────────────────────────
print("\n-- Database (direct psycopg2) --")

def check_db_connection():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("SELECT version()")
    version = cur.fetchone()[0]
    conn.close()
    return version.split(",")[0]   # e.g. "PostgreSQL 15.1 on x86_64..."

check("PostgreSQL connection", check_db_connection)


def check_pgvector():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("SELECT extversion FROM pg_extension WHERE extname = 'vector'")
    row = cur.fetchone()
    conn.close()
    if not row:
        raise RuntimeError("pgvector extension not installed")
    return f"pgvector v{row[0]} installed"

check("pgvector extension", check_pgvector)


def check_tables():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("""
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = 'public'
        AND table_name IN ('documents', 'chunks')
        ORDER BY table_name
    """)
    tables = [r[0] for r in cur.fetchall()]
    conn.close()
    if set(tables) != {"documents", "chunks"}:
        raise RuntimeError(f"Expected 'documents' and 'chunks', found: {tables}")
    return "tables: documents, chunks"

check("Schema tables exist", check_tables)


# ── Check 3: Document inventory ───────────────────────────────────────────────
print("\n-- Document Inventory --")

def check_documents():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("""
        SELECT
            d.original_filename,
            d.subject,
            d.school_year,
            d.teacher,
            d.doc_type,
            d.ingested_at::date::text,
            COUNT(CASE WHEN c.chunk_size = 'small' THEN 1 END) AS small_chunks,
            COUNT(CASE WHEN c.chunk_size = 'large' THEN 1 END) AS large_chunks
        FROM documents d
        LEFT JOIN chunks c ON c.document_id = d.id
        GROUP BY d.id
        ORDER BY d.ingested_at DESC
    """)
    rows = cur.fetchall()
    conn.close()
    if not rows:
        raise RuntimeError("No documents found — run ingest.py first")
    lines = []
    for r in rows:
        fname, subj, yr, tchr, dtype, date, sm, lg = r
        meta = " | ".join(filter(None, [subj, yr, tchr, dtype]))
        lines.append(f"{fname}  [{meta}]  {sm} small / {lg} large chunks  (ingested {date})")
    return "\n".join(lines)

check(f"Documents ingested", check_documents)


def check_chunks():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("SELECT chunk_size, COUNT(*) FROM chunks GROUP BY chunk_size ORDER BY chunk_size")
    rows = cur.fetchall()
    conn.close()
    if not rows:
        raise RuntimeError("No chunks found")
    return "  ".join(f"{size}: {count}" for size, count in rows)

check("Chunk counts by size", check_chunks)


def check_vector_dim():
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("SELECT vector_dims(embedding) FROM chunks LIMIT 1")
    row = cur.fetchone()
    conn.close()
    if not row:
        raise RuntimeError("No chunks with embeddings")
    dim = row[0]
    if dim != 1536:
        raise RuntimeError(f"Expected 1536 dimensions, got {dim} — embedding model mismatch!")
    return f"1536 dimensions (OpenAI text-embedding-3-small confirmed)"

check("Embedding dimensions", check_vector_dim)


# ── Check 4: OpenAI embeddings ─────────────────────────────────────────────────
print("\n-- OpenAI Embeddings --")

_embedding_cache = None

def check_openai_embed():
    global _embedding_cache
    client = OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    response = client.embeddings.create(input=["test query"], model="text-embedding-3-small")
    _embedding_cache = response.data[0].embedding
    return f"Got {len(_embedding_cache)}-dim embedding for test query"

check("OpenAI embedding API", check_openai_embed)


# ── Check 5: Direct vector search (psycopg2) ──────────────────────────────────
print("\n-- Vector Search (direct) --")

def check_direct_search():
    if _embedding_cache is None:
        raise RuntimeError("Skipped — OpenAI embedding check failed")
    vec_str = "[" + ",".join(f"{x:.8f}" for x in _embedding_cache) + "]"
    conn = psycopg2.connect(os.environ["DATABASE_URL"])
    cur = conn.cursor()
    cur.execute("""
        SELECT d.original_filename, ROUND((1 - (c.embedding <=> %s::vector))::numeric, 3) AS sim
        FROM chunks c JOIN documents d ON c.document_id = d.id
        ORDER BY c.embedding <=> %s::vector
        LIMIT 3
    """, (vec_str, vec_str))
    rows = cur.fetchall()
    conn.close()
    if not rows:
        raise RuntimeError("Vector search returned no results")
    return "\n".join(f"{r[0]}  similarity={r[1]}" for r in rows)

check("Vector similarity search", check_direct_search)


# ── Check 6: Supabase REST API ─────────────────────────────────────────────────
print("\n-- Supabase REST API (used by web page) --")

def supabase_get(path):
    url = os.environ["SUPABASE_URL"] + path
    req = Request(url)
    req.add_header("apikey", os.environ["SUPABASE_ANON_KEY"])
    req.add_header("Authorization", "Bearer " + os.environ["SUPABASE_ANON_KEY"])
    with urlopen(req, timeout=10) as r:
        return json.loads(r.read().decode())

def supabase_rpc(fn, params):
    url = os.environ["SUPABASE_URL"] + f"/rest/v1/rpc/{fn}"
    body = json.dumps(params).encode()
    req = Request(url, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("apikey", os.environ["SUPABASE_ANON_KEY"])
    req.add_header("Authorization", "Bearer " + os.environ["SUPABASE_ANON_KEY"])
    with urlopen(req, timeout=15) as r:
        return json.loads(r.read().decode())

def check_supabase_docs():
    rows = supabase_get("/rest/v1/documents?select=original_filename,subject,school_year")
    if not rows:
        raise RuntimeError("No documents returned from Supabase REST API")
    return f"{len(rows)} document(s) readable via anon key"

check("Supabase REST — documents table readable", check_supabase_docs)


def check_supabase_search_fn():
    if _embedding_cache is None:
        raise RuntimeError("Skipped — OpenAI embedding check failed")
    rows = supabase_rpc("search_ohs_memory", {
        "query_embedding": _embedding_cache,
        "match_count": 2
    })
    if not rows:
        raise RuntimeError("search_ohs_memory() returned no results")
    if "error" in (rows[0] if rows else {}):
        raise RuntimeError(f"RPC error: {rows[0]['error']}")
    top = rows[0]
    return (
        f"search_ohs_memory() returned {len(rows)} result(s)\n"
        f"Top hit: {top.get('original_filename')}  similarity={round(float(top.get('similarity',0)),3)}"
    )

check("Supabase RPC — search_ohs_memory()", check_supabase_search_fn)


def check_supabase_list_fn():
    rows = supabase_rpc("list_ohs_documents", {})
    if not rows:
        raise RuntimeError("list_ohs_documents() returned nothing")
    return f"list_ohs_documents() returned {len(rows)} document(s)"

check("Supabase RPC — list_ohs_documents()", check_supabase_list_fn)


# ── Check 7: Meaningful search ────────────────────────────────────────────────
print("\n-- Meaningful Search Test --")

def check_what_is_this():
    if _embedding_cache is None:
        raise RuntimeError("Skipped")
    client = OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    embedding = client.embeddings.create(
        input=["what is OHS Memory and what is it for"],
        model="text-embedding-3-small"
    ).data[0].embedding
    rows = supabase_rpc("search_ohs_memory", {"query_embedding": embedding, "match_count": 1})
    if not rows:
        raise RuntimeError("No results for 'what is OHS Memory'")
    top = rows[0]
    sim = round(float(top.get("similarity", 0)), 3)
    snippet = top.get("content", "")[:120].replace("\n", " ")
    return f"similarity={sim}  \"{snippet}...\""

check("Semantic search — 'what is OHS Memory'", check_what_is_this)


# ── Summary ───────────────────────────────────────────────────────────────────
print("\n" + "=" * 50)
passed = sum(1 for ok, _ in results if ok)
failed = sum(1 for ok, _ in results if not ok)
print(f"  {passed}/{len(results)} checks passed", end="")
if failed:
    print(f"  |  {failed} FAILED:")
    for ok, label in results:
        if not ok:
            print(f"    - {label}")
else:
    print("  -- all systems go")
print()

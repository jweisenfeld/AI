"""
OHS Memory — MCP Server
========================
Exposes OHS organizational memory as MCP tools for Claude Code / Claude Desktop.

Runs locally via stdio — no hosting required.
The MCP client launches this process; it connects to Supabase over the internet.

Claude Desktop config (~/.claude/claude_desktop_config.json on Mac,
%APPDATA%\Claude\claude_desktop_config.json on Windows):

{
  "mcpServers": {
    "ohs-memory": {
      "command": "python",
      "args": ["C:/Users/johnw/Documents/GitHub/AI/ohs-memory/mcp_server.py"],
      "env": {
        "OPENAI_API_KEY": "sk-proj-...",
        "SUPABASE_URL": "https://xxxx.supabase.co",
        "SUPABASE_ANON_KEY": "eyJ..."
      }
    }
  }
}
"""

import json
import os
from typing import Optional
from urllib.request import Request, urlopen
from urllib.error import URLError

from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP
from openai import OpenAI

load_dotenv()

EMBEDDING_MODEL = "text-embedding-3-small"

mcp = FastMCP(
    "OHS Memory",
    instructions=(
        "You have access to Orion High School's organizational memory — "
        "a searchable database of decision documents, meeting notes, policies, "
        "lesson plans, and emails going back to the school's founding. "
        "Use search_decisions() to answer questions about school practices, "
        "history, curriculum, and culture. Always cite the source document."
    ),
)

_openai: OpenAI | None = None


def get_openai():
    global _openai
    if _openai is None:
        _openai = OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _openai


def embed(text: str) -> list[float]:
    response = get_openai().embeddings.create(input=[text], model=EMBEDDING_MODEL)
    return response.data[0].embedding


def supabase_rpc(function_name: str, params: dict) -> list[dict]:
    """Call a Supabase PostgreSQL function via REST API."""
    url = f"{os.environ['SUPABASE_URL']}/rest/v1/rpc/{function_name}"
    body = json.dumps(params).encode("utf-8")

    req = Request(url, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("apikey", os.environ["SUPABASE_ANON_KEY"])
    req.add_header("Authorization", f"Bearer {os.environ['SUPABASE_ANON_KEY']}")

    try:
        with urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except URLError as e:
        return [{"error": str(e)}]


# ── Tools ──────────────────────────────────────────────────────────────────────

@mcp.tool()
def search_decisions(
    query: str,
    subject: Optional[str] = None,
    school_year: Optional[str] = None,
    doc_type: Optional[str] = None,
    teacher: Optional[str] = None,
    chunk_size: Optional[str] = None,
    limit: int = 6,
) -> str:
    """
    Search Orion High School's organizational memory for decisions, policies,
    meeting notes, lesson plans, and institutional history.

    Use this tool to answer questions like:
    - "What is our policy on late work?"
    - "What did we decide about phones in class?"
    - "What economics assignments were used in 2025-26?"
    - "How do we handle student conflicts?"
    - "What units does Physics cover in semester 1?"

    Args:
        query:       Natural language question. Be descriptive.
        subject:     Filter by subject (e.g. "Economics", "Physics", "All-Staff")
        school_year: Filter by year (e.g. "2025-26")
        doc_type:    "lesson_plan", "meeting_notes", "policy", "email", or "other"
        teacher:     Filter by teacher/author name
        chunk_size:  "small" for precise facts, "large" for full context, None for both
        limit:       Results to return (default 6, max 20)
    """
    limit = min(max(1, limit), 20)
    embedding = embed(query)

    params: dict = {
        "query_embedding":  embedding,
        "match_count":      limit,
    }
    if subject:     params["filter_subject"]    = subject
    if school_year: params["filter_year"]       = school_year
    if doc_type:    params["filter_doc_type"]   = doc_type
    if teacher:     params["filter_teacher"]    = teacher
    if chunk_size in ("small", "large"):
        params["filter_chunk_size"] = chunk_size

    rows = supabase_rpc("search_ohs_memory", params)

    if not rows:
        return json.dumps({
            "query": query,
            "results": [],
            "note": "No results found. The corpus may not contain documents on this topic yet.",
        }, indent=2)

    if isinstance(rows, list) and rows and "error" in rows[0]:
        return json.dumps({"error": rows[0]["error"]}, indent=2)

    return json.dumps({
        "query":        query,
        "result_count": len(rows),
        "results": [
            {
                "similarity":  round(float(r.get("similarity", 0)), 3),
                "source":      r.get("original_filename"),
                "chunk_size":  r.get("chunk_size"),
                "subject":     r.get("subject"),
                "school_year": r.get("school_year"),
                "teacher":     r.get("teacher"),
                "doc_type":    r.get("doc_type"),
                "unit":        r.get("unit"),
                "content":     r.get("content", "").strip(),
            }
            for r in rows
        ],
    }, indent=2)


@mcp.tool()
def list_documents(
    subject: Optional[str] = None,
    school_year: Optional[str] = None,
    doc_type: Optional[str] = None,
) -> str:
    """
    List all documents in the OHS Memory archive.
    Use this to discover what's in the database before searching,
    or to answer "what lesson plans do we have for Economics 2025-26?"

    Args:
        subject:     Filter by subject area
        school_year: Filter by school year
        doc_type:    Filter by type
    """
    params: dict = {}
    if subject:   params["filter_subject"]   = subject
    if school_year: params["filter_year"]    = school_year
    if doc_type:  params["filter_doc_type"]  = doc_type

    rows = supabase_rpc("list_ohs_documents", params)

    if not rows:
        return json.dumps({"count": 0, "documents": [],
            "note": "No documents found. Run ingest.py to add documents."})

    return json.dumps({
        "count":     len(rows),
        "documents": rows,
    }, indent=2)


@mcp.tool()
def get_corpus_stats() -> str:
    """
    Return statistics about the OHS Memory knowledge base:
    document counts by type, year, subject, and ingestion timeline.
    Useful for understanding the coverage and gaps in org memory.
    """
    # Run raw stats query via a simple select
    url = f"{os.environ['SUPABASE_URL']}/rest/v1/documents?select=doc_type,school_year,subject,ingested_at"
    req = Request(url)
    req.add_header("apikey", os.environ["SUPABASE_ANON_KEY"])
    req.add_header("Authorization", f"Bearer {os.environ['SUPABASE_ANON_KEY']}")

    try:
        with urlopen(req, timeout=10) as resp:
            docs = json.loads(resp.read().decode("utf-8"))
    except URLError as e:
        return json.dumps({"error": str(e)})

    from collections import Counter
    by_type    = Counter(d.get("doc_type")    for d in docs if d.get("doc_type"))
    by_year    = Counter(d.get("school_year") for d in docs if d.get("school_year"))
    by_subject = Counter(d.get("subject")     for d in docs if d.get("subject"))

    return json.dumps({
        "total_documents": len(docs),
        "by_doc_type":     dict(by_type.most_common()),
        "by_school_year":  dict(by_year.most_common()),
        "by_subject":      dict(by_subject.most_common()),
    }, indent=2)


if __name__ == "__main__":
    mcp.run()

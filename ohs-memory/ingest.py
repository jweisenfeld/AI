"""
OHS Memory — PDF Ingestion Script
===================================
Reads a PDF, extracts text via Claude API, chunks at two sizes,
embeds with OpenAI text-embedding-3-small, stores in Supabase (pgvector).

Usage:
    python ingest.py path/to/document.pdf
    python ingest.py path/to/folder/        # batch-ingest all PDFs in a folder
    python ingest.py notes.pdf --subject "All-Staff" --year 2025-26 --type meeting_notes
"""

import argparse
import base64
import hashlib
import os
import sys
from pathlib import Path

import anthropic
import psycopg2
import tiktoken
from dotenv import load_dotenv
from openai import OpenAI
from tqdm import tqdm

load_dotenv()

# ── Configuration ──────────────────────────────────────────────────────────────

SMALL_CHUNK_TOKENS  = 200   # precise, factual retrieval
SMALL_CHUNK_OVERLAP = 40
LARGE_CHUNK_TOKENS  = 900   # contextual retrieval
LARGE_CHUNK_OVERLAP = 120

# Claude model for PDF text extraction
EXTRACTION_MODEL = "claude-haiku-4-5"  # fast + cheap; upgrade to claude-opus-4-6 for complex PDFs

# OpenAI embedding model — MUST match what mcp_server.py and search-proxy.php use
EMBEDDING_MODEL    = "text-embedding-3-small"
EMBEDDING_DIM      = 1536

# ── Client initialization ──────────────────────────────────────────────────────
_anthropic: anthropic.Anthropic | None = None
_openai: OpenAI | None = None
_tokenizer = None


def get_anthropic():
    global _anthropic
    if _anthropic is None:
        _anthropic = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
    return _anthropic


def get_openai():
    global _openai
    if _openai is None:
        _openai = OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _openai


def get_tokenizer():
    global _tokenizer
    if _tokenizer is None:
        _tokenizer = tiktoken.get_encoding("cl100k_base")
    return _tokenizer


# ── Core functions ─────────────────────────────────────────────────────────────

def hash_file(path: Path) -> str:
    """SHA-256 of raw file bytes. Used to skip already-ingested documents."""
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(65536), b""):
            h.update(block)
    return h.hexdigest()


def extract_text(path: Path) -> str:
    """
    Extract text from a file. Strategy depends on file type:
    - .md / .txt — read directly as plain text (no API call needed)
    - .pdf       — send to Claude API for extraction
    """
    suffix = path.suffix.lower()
    if suffix in (".md", ".txt"):
        print(f"  Reading plain text file directly...")
        return path.read_text(encoding="utf-8", errors="replace")
    if suffix == ".pdf":
        return _extract_text_from_pdf(path)
    raise ValueError(f"Unsupported file type: {suffix}. Supported: .pdf, .md, .txt")


def _extract_text_from_pdf(path: Path) -> str:
    """
    Send the PDF to Claude API and get back clean extracted text.
    Handles: meeting notes, slide decks, policy docs, printed emails.
    Streams the response to avoid timeout on long documents.
    """
    with open(path, "rb") as f:
        pdf_b64 = base64.standard_b64encode(f.read()).decode("utf-8")

    print(f"  Extracting text via Claude API ({EXTRACTION_MODEL})...")

    with get_anthropic().messages.stream(
        model=EXTRACTION_MODEL,
        max_tokens=8192,
        messages=[{
            "role": "user",
            "content": [
                {
                    "type": "document",
                    "source": {
                        "type": "base64",
                        "media_type": "application/pdf",
                        "data": pdf_b64,
                    },
                },
                {
                    "type": "text",
                    "text": (
                        "Extract ALL text from this document. "
                        "Preserve structure: headings, bullet points, numbered lists, tables. "
                        "If this is a slide deck, include ALL slide content. "
                        "Do NOT summarize — extract the complete text verbatim. "
                        "Return ONLY the extracted text with no commentary."
                    ),
                },
            ],
        }],
    ) as stream:
        final = stream.get_final_message()

    return final.content[0].text


def chunk_text(text: str, max_tokens: int, overlap: int) -> list[str]:
    """Split text into overlapping token-counted chunks."""
    enc = get_tokenizer()
    tokens = enc.encode(text)
    if not tokens:
        return []

    chunks = []
    start = 0
    while start < len(tokens):
        end = min(start + max_tokens, len(tokens))
        chunks.append(enc.decode(tokens[start:end]))
        start += max(max_tokens - overlap, 1)
    return chunks


def embed_texts(texts: list[str]) -> list[list[float]]:
    """
    Embed a list of texts using OpenAI text-embedding-3-small.
    Returns list of 1536-dimensional float vectors.
    Batches requests to stay within API limits.
    """
    all_embeddings = []
    batch_size = 100  # OpenAI allows up to 2048 per request; 100 is safe

    for i in range(0, len(texts), batch_size):
        batch = texts[i : i + batch_size]
        response = get_openai().embeddings.create(
            input=batch,
            model=EMBEDDING_MODEL,
        )
        all_embeddings.extend([item.embedding for item in response.data])

    return all_embeddings


def format_vector(v: list[float]) -> str:
    """Format float list as pgvector literal string: '[0.1,0.2,...]'"""
    return "[" + ",".join(f"{x:.8f}" for x in v) + "]"


def prompt_metadata(filename: str) -> dict:
    """Interactively collect metadata for the document being ingested."""
    print(f"\n  📋 Metadata for: {filename}")
    print("  (Press Enter to skip any field)\n")

    def ask(prompt, options=None):
        if options:
            print(f"     Options: {', '.join(options)}")
        value = input(f"  {prompt}: ").strip()
        return value if value else None

    subject    = ask("Subject (e.g. Economics, Physics, All-Staff)")
    school_year = ask("School year (e.g. 2025-26)")
    teacher    = ask("Teacher/author (e.g. Weisenfeld)")
    doc_type   = ask("Document type", ["lesson_plan", "meeting_notes", "policy", "email", "other"])
    if doc_type and doc_type not in ("lesson_plan", "meeting_notes", "policy", "email", "other"):
        doc_type = "other"
    unit  = ask("Unit/topic (e.g. Supply and Demand)")
    notes = ask("Any notes about this document")

    return {
        "subject":     subject,
        "school_year": school_year,
        "teacher":     teacher,
        "doc_type":    doc_type or "other",
        "unit":        unit,
        "notes":       notes,
    }


def ingest_pdf(path: Path, metadata: dict | None = None, db_url: str | None = None) -> str | None:
    """
    Full ingestion pipeline for a single PDF.
    Returns the document UUID on success, None if skipped/failed.
    """
    db_url = db_url or os.environ["DATABASE_URL"]
    conn = psycopg2.connect(db_url)
    cur  = conn.cursor()

    try:
        # 1. Hash check — skip if already ingested
        file_hash = hash_file(path)
        cur.execute("SELECT id FROM documents WHERE source_hash = %s", (file_hash,))
        if existing := cur.fetchone():
            print(f"  [skip] Already ingested: {path.name} (id: {existing[0]})")
            return None

        # 2. Prompt for metadata if not provided
        if metadata is None:
            metadata = prompt_metadata(path.name)

        # 3. Extract text
        raw_text = extract_text(path)
        token_count = len(get_tokenizer().encode(raw_text))
        print(f"  Extracted {token_count:,} tokens")

        # 4. Insert document record
        cur.execute(
            """
            INSERT INTO documents
                (source_hash, source_file, original_filename,
                 subject, school_year, teacher, doc_type, unit, notes, raw_text)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            RETURNING id
            """,
            (
                file_hash, str(path.resolve()), path.name,
                metadata.get("subject"),
                metadata.get("school_year"),
                metadata.get("teacher"),
                metadata.get("doc_type", "other"),
                metadata.get("unit"),
                metadata.get("notes"),
                raw_text,
            ),
        )
        doc_id = cur.fetchone()[0]

        # 5. Chunk → embed → insert (for both sizes)
        enc = get_tokenizer()
        configs = [
            ("small", SMALL_CHUNK_TOKENS, SMALL_CHUNK_OVERLAP),
            ("large", LARGE_CHUNK_TOKENS, LARGE_CHUNK_OVERLAP),
        ]

        for label, max_tok, overlap in configs:
            chunks = chunk_text(raw_text, max_tok, overlap)
            if not chunks:
                continue

            print(f"  Embedding {len(chunks)} {label} chunks via OpenAI...")
            embeddings = embed_texts(chunks)

            rows = [
                (
                    doc_id, label, chunk,
                    format_vector(emb),
                    i,
                    len(enc.encode(chunk)),
                )
                for i, (chunk, emb) in enumerate(zip(chunks, embeddings))
            ]

            cur.executemany(
                """
                INSERT INTO chunks (document_id, chunk_size, content, embedding, position, token_count)
                VALUES (%s, %s, %s, %s::vector, %s, %s)
                """,
                rows,
            )
            print(f"  [ok] {len(chunks)} {label} chunks stored")

        conn.commit()
        print(f"\n  [DONE] {path.name}  (id: {doc_id})\n")
        return str(doc_id)

    except Exception as e:
        conn.rollback()
        print(f"\n  [FAIL] {path.name} -- {e}\n")
        raise
    finally:
        cur.close()
        conn.close()


# ── CLI ────────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Ingest PDF documents into the OHS Memory database.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python ingest.py meeting_notes.pdf
  python ingest.py econ_unit2.pdf --subject Economics --year 2025-26 --type lesson_plan
  python ingest.py ./docs/                # batch-ingest a folder
        """,
    )
    parser.add_argument("path", help="PDF file or folder of PDFs to ingest")
    parser.add_argument("--subject",  help="Subject (skips interactive prompt)")
    parser.add_argument("--year",     help="School year, e.g. 2025-26")
    parser.add_argument("--teacher",  help="Teacher/author name")
    parser.add_argument("--type", dest="doc_type",
        choices=["lesson_plan", "meeting_notes", "policy", "email", "other"],
        help="Document type",
    )
    parser.add_argument("--unit", help="Unit or topic name")
    args = parser.parse_args()

    cli_metadata = None
    if any([args.subject, args.year, args.teacher, args.doc_type, args.unit]):
        cli_metadata = {
            "subject":     args.subject,
            "school_year": args.year,
            "teacher":     args.teacher,
            "doc_type":    args.doc_type or "other",
            "unit":        args.unit,
            "notes":       None,
        }

    target = Path(args.path)

    SUPPORTED = {".pdf", ".md", ".txt"}

    if target.is_dir():
        files = sorted(f for f in target.glob("**/*") if f.suffix.lower() in SUPPORTED)
        if not files:
            print(f"No supported files (.pdf, .md, .txt) found in {target}")
            sys.exit(1)
        print(f"Found {len(files)} file(s) in {target}\n")
        for f in tqdm(files, desc="Ingesting", unit="file"):
            ingest_pdf(f, metadata=cli_metadata)
    elif target.is_file() and target.suffix.lower() in SUPPORTED:
        ingest_pdf(target, metadata=cli_metadata)
    else:
        print(f"Error: {target} is not a supported file (.pdf, .md, .txt) or directory")
        sys.exit(1)


if __name__ == "__main__":
    main()

"""
FlightLog — Disaster Recovery Backup
=====================================
Exports the documents table (metadata + raw_text, NO embeddings) to a
timestamped JSON file.  This is the critical backup — everything else
can be regenerated:

  Source files     → already on disk / OneDrive / SharePoint
  Embeddings       → re-generated from raw_text via ingest.py
  Schema           → schema.sql in git
  query_log        → nice to have, not critical

The backup file is self-contained enough to restore to any PostgreSQL +
pgvector provider within 24-48 hours using restore.py.

Usage:
    python backup.py                          # saves to ./backups/
    python backup.py --out D:\\Backups\\FlightLog\\

Recommended schedule: weekly, output saved to OneDrive so it survives
a local disk failure too.

    # Windows Task Scheduler (weekly, Monday 6am):
    python C:\\path\\to\\backup.py --out "C:\\Users\\johnw\\OneDrive - PSD1\\FlightLog-Backups\\"
"""

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import psycopg2
import psycopg2.extras


def backup(out_dir: Path) -> Path:
    db_url = os.environ.get("DATABASE_URL")
    if not db_url:
        print("ERROR: DATABASE_URL environment variable not set.", file=sys.stderr)
        sys.exit(1)

    out_dir.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    out_path  = out_dir / f"flightlog_backup_{timestamp}.json"

    print(f"Connecting to database...")
    conn = psycopg2.connect(db_url)
    cur  = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)

    # ── documents (the critical table) ───────────────────────────────────────
    print("Exporting documents table (metadata + raw_text)...")
    cur.execute("""
        SELECT id, source_hash, source_file, original_filename,
               ingested_at, subject, school_year, teacher,
               doc_type, unit, notes, raw_text
        FROM documents
        ORDER BY ingested_at
    """)
    documents = []
    for row in cur:
        r = dict(row)
        if r.get("ingested_at"):
            r["ingested_at"] = r["ingested_at"].isoformat()
        documents.append(r)

    # ── query_log (nice to have) ──────────────────────────────────────────────
    print("Exporting query_log...")
    cur.execute("""
        SELECT id, query_text, result_count, had_answer, queried_at
        FROM query_log
        ORDER BY queried_at
    """)
    queries = []
    for row in cur:
        r = dict(row)
        if r.get("queried_at"):
            r["queried_at"] = r["queried_at"].isoformat()
        queries.append(r)

    cur.close()
    conn.close()

    # ── Write output ──────────────────────────────────────────────────────────
    payload = {
        "backup_version": 1,
        "created_at":     datetime.now(timezone.utc).isoformat(),
        "note": (
            "Embeddings NOT included — regenerate with ingest.py using raw_text. "
            "Schema is in schema.sql. See restore.py for recovery instructions."
        ),
        "counts": {
            "documents": len(documents),
            "query_log": len(queries),
        },
        "documents": documents,
        "query_log":  queries,
    }

    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)

    size_mb = out_path.stat().st_size / 1024 / 1024
    print(f"\n✓ Backup complete:")
    print(f"  Documents : {len(documents):,}")
    print(f"  Queries   : {len(queries):,}")
    print(f"  File      : {out_path}")
    print(f"  Size      : {size_mb:.1f} MB")
    return out_path


def main():
    parser = argparse.ArgumentParser(description="FlightLog disaster-recovery backup.")
    parser.add_argument(
        "--out", default="backups",
        help="Output directory (default: ./backups/). Use a OneDrive path for offsite safety."
    )
    args = parser.parse_args()
    backup(Path(args.out))


if __name__ == "__main__":
    main()

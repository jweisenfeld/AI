"""
FlightLog — Disaster Recovery Restore
======================================
Restores a backup.py JSON file to a fresh PostgreSQL + pgvector database.

After restore, re-run ingest.py in re-embed mode to regenerate the
vector embeddings from the stored raw_text.  This takes a few hours
and costs ~$5-10 in OpenAI embedding API fees for a typical corpus.

Step-by-step recovery (meteorite scenario):
--------------------------------------------
1.  Spin up a new database:
      - Neon (neon.tech) — free tier, pgvector built in, best Supabase alt
      - Render (render.com/docs/postgresql) — free tier, install pgvector
      - Railway (railway.app) — pgvector available
      - Or re-create on Supabase itself if they recover

2.  Set the new DATABASE_URL in your environment (or .env):
      export DATABASE_URL="postgresql://user:pass@host:5432/dbname"

3.  Apply the schema:
      psql $DATABASE_URL -f schema.sql

4.  Restore the documents and query_log:
      python restore.py backups/flightlog_backup_YYYYMMDD_HHMMSS.json

5.  Re-embed (regenerates all vector chunks from stored raw_text):
      python ingest.py --re-embed
    (this re-reads raw_text from the DB and re-creates all chunks + embeddings)
    Estimated time: 2-4 hours for ~2,000 documents
    Estimated cost: ~$5-10 OpenAI embedding fees

6.  Update .secrets/ohskey.php on psd1.net with the new SUPABASE_URL
    and SUPABASE_ANON_KEY (or equivalent for the new provider).

7.  Re-create the RPC functions by running schema.sql in the new DB's
    SQL editor (step 3 already covers this).

8.  Test: visit psd1.net/ohs-search and run a query.

Total estimated recovery time: 4-8 hours.
"""

import argparse
import json
import os
import sys
from pathlib import Path

import psycopg2
import psycopg2.extras


def restore(backup_path: Path, dry_run: bool = False) -> None:
    db_url = os.environ.get("DATABASE_URL")
    if not db_url:
        print("ERROR: DATABASE_URL environment variable not set.", file=sys.stderr)
        sys.exit(1)

    print(f"Loading backup: {backup_path}")
    with open(backup_path, encoding="utf-8") as f:
        data = json.load(f)

    documents = data.get("documents", [])
    queries   = data.get("query_log", [])
    counts    = data.get("counts", {})

    print(f"Backup created : {data.get('created_at', 'unknown')}")
    print(f"Documents      : {counts.get('documents', len(documents)):,}")
    print(f"Query log      : {counts.get('query_log', len(queries)):,}")
    print(f"Note           : {data.get('note', '')}")
    print()

    if dry_run:
        print("DRY RUN — no changes made.")
        return

    conn = psycopg2.connect(db_url)
    cur  = conn.cursor()

    # ── Restore documents ─────────────────────────────────────────────────────
    print(f"Restoring {len(documents):,} documents...")
    ok = skip = 0
    for doc in documents:
        cur.execute("SELECT id FROM documents WHERE source_hash = %s", (doc["source_hash"],))
        if cur.fetchone():
            skip += 1
            continue
        cur.execute(
            """
            INSERT INTO documents
                (id, source_hash, source_file, original_filename, ingested_at,
                 subject, school_year, teacher, doc_type, unit, notes, raw_text)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (source_hash) DO NOTHING
            """,
            (
                doc["id"], doc["source_hash"], doc["source_file"],
                doc["original_filename"], doc.get("ingested_at"),
                doc.get("subject"), doc.get("school_year"), doc.get("teacher"),
                doc.get("doc_type"), doc.get("unit"), doc.get("notes"),
                doc.get("raw_text"),
            ),
        )
        ok += 1
        if ok % 100 == 0:
            conn.commit()
            print(f"  {ok:,} inserted, {skip:,} skipped (already present)...")

    conn.commit()
    print(f"  Documents done: {ok:,} inserted, {skip:,} skipped")

    # ── Restore query_log ────────────────────────────────────────────────────
    if queries:
        print(f"Restoring {len(queries):,} query log entries...")
        for q in queries:
            cur.execute(
                """
                INSERT INTO query_log (query_text, result_count, had_answer, queried_at)
                VALUES (%s, %s, %s, %s)
                """,
                (q["query_text"], q["result_count"], q["had_answer"], q.get("queried_at")),
            )
        conn.commit()
        print(f"  Query log done: {len(queries):,} entries")

    cur.close()
    conn.close()

    print(f"""
✓ Restore complete.

Next step — regenerate embeddings:
    python ingest.py --re-embed

This re-reads raw_text from the restored documents and creates all
vector chunks.  Expect 2-4 hours and ~$5-10 in OpenAI API fees.
""")


def main():
    parser = argparse.ArgumentParser(
        description="Restore a FlightLog backup JSON to a fresh database.",
        epilog=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("backup_file", help="Path to the backup JSON file from backup.py")
    parser.add_argument("--dry-run", action="store_true",
                        help="Validate the backup file without writing to the database")
    args = parser.parse_args()

    backup_path = Path(args.backup_file)
    if not backup_path.exists():
        print(f"ERROR: File not found: {backup_path}", file=sys.stderr)
        sys.exit(1)

    restore(backup_path, dry_run=args.dry_run)


if __name__ == "__main__":
    main()

# OHS Memory — Setup Guide

## ❌ Bluehost Shared Hosting Won't Work

Bluehost shared hosting uses **MySQL only** — no PostgreSQL, no pgvector extension,
and no persistent Python processes. The MCP server can't run there.

## ✅ The Good News: You Don't Need a Server

MCP servers using stdio transport run **locally on each teacher's machine**.
The MCP client (Claude Code, Claude Desktop) launches the Python process itself.
The only thing that needs to be "hosted" is the **database**.

---

## Recommended Architecture

```
Each teacher's laptop
├── Python + this repo installed locally
├── mcp_server.py  ← runs as a local process, zero hosting cost
└── .env           ← points to shared Supabase database
         │
         ▼  PostgreSQL connection (DATABASE_URL)
    Supabase (free tier)
    └── PostgreSQL + pgvector  ← the shared org memory
```

---

## Step 1: Set Up the Database (Supabase — Free)

1. Go to https://supabase.com → **New project**
2. Name it `ohs-memory`, set a strong password, choose a region
3. Wait ~2 minutes for it to provision
4. Go to **SQL Editor** → paste the entire contents of `schema.sql` → **Run**
5. Go to **Settings → Database → Connection string (URI)** → copy it
6. It looks like: `postgresql://postgres:[PASSWORD]@db.[REF].supabase.co:5432/postgres`

That's your `DATABASE_URL`. Free tier gives you 500MB, enough for years of docs.

---

## Step 2: Install Python Dependencies

```bash
cd ohs-memory
pip install -r requirements.txt
```

First run downloads the embedding model (~90MB). After that, it's cached.

---

## Step 3: Configure Environment

```bash
cp .env.example .env
# Edit .env and fill in:
#   ANTHROPIC_API_KEY=sk-ant-...
#   DATABASE_URL=postgresql://...
```

---

## Step 4: Ingest Your First Document

```bash
# Single file (will prompt for metadata)
python ingest.py path/to/your/meeting-notes.pdf

# With metadata pre-filled (no prompts)
python ingest.py notes.pdf --subject "All-Staff" --year 2025-26 --type meeting_notes

# Whole folder
python ingest.py ./docs/
```

---

## Step 5: Test Search

```bash
# Basic search
python search.py "how do we handle late work?"

# THE MAIN EVENT: compare small vs large chunks
python search.py "phone policy" --compare

# Filter by subject and year
python search.py "unit 2 assignments" --subject Economics --year 2025-26

# List everything in the database
python search.py --list
```

---

## Step 6: Connect to Claude

### Claude Code (recommended for teachers who use it)

Add to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "ohs-memory": {
      "command": "python",
      "args": ["/full/path/to/ohs-memory/mcp_server.py"],
      "env": {
        "ANTHROPIC_API_KEY": "sk-ant-...",
        "DATABASE_URL": "postgresql://..."
      }
    }
  }
}
```

### Claude Desktop App

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (Mac)
or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "ohs-memory": {
      "command": "python",
      "args": ["C:\\path\\to\\ohs-memory\\mcp_server.py"],
      "env": {
        "ANTHROPIC_API_KEY": "sk-ant-...",
        "DATABASE_URL": "postgresql://..."
      }
    }
  }
}
```

Restart Claude Desktop. The "ohs-memory" tools will appear automatically.

---

## Monthly Cost

| Item | Cost |
|------|------|
| Supabase database (free tier, up to 500MB) | **$0** |
| Embedding model (runs locally) | **$0** |
| MCP server (runs locally) | **$0** |
| Claude API for PDF extraction (~$0.01/doc avg) | **~$1–5/year** |
| **Total** | **~$0–5/year** |

If you outgrow Supabase's free 500MB: upgrade to $25/month (8GB).
For reference: 1,000 lesson plans + meeting notes ≈ ~50MB. You won't hit the limit soon.

---

## Re-ingesting with Better Models (Future-Proofing)

When better embedding models are available (they will be), re-embed everything:

```sql
-- Delete all chunk embeddings (keeps raw_text and documents)
DELETE FROM chunks;
```

Then update `EMBEDDING_MODEL` in `ingest.py` and `mcp_server.py` to the new model,
and re-run ingest on your PDF archive. The `source_hash` dedup will be skipped since
chunks were deleted, but the `raw_text` column means you don't need the original PDFs.

---

## For New Hires

Give them:
1. Access to the Supabase project (read-only credentials)
2. A copy of this repo
3. Their `.env` file pre-filled
4. The MCP config snippet above

They're set up in 10 minutes.

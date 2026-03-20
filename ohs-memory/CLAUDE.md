# OHS Memory — Ingestion Pipeline & MCP Server

## What This Is

OHS Memory is Orion High School's organizational knowledge base. It is a system for ingesting decision documents, meeting notes, lesson plans, policies, and emails into a searchable vector database, so that any teacher — new or veteran — can ask questions about how we do things and get answers grounded in our actual history.

The project was built in March 2026 by John Weisenfeld, with the goal of preserving institutional memory as the school grows from 8 to 15+ teachers. The core insight: the hard part is not the technology, it is the discipline of consistently archiving decisions. The technology just makes the archive useful.

## The Problem This Solves

Before this system existed, organizational knowledge at Orion High School lived in individual teachers' heads, in scattered SharePoint folders, in email threads, and in meeting notes that nobody could find. When a new teacher joined, they had to absorb years of decisions through conversations, trial and error, and guesswork. This system makes that knowledge searchable and persistent.

## How It Works

### Ingestion Pipeline

Documents (PDFs) are ingested through a Python script that:
1. Computes a SHA-256 hash of the file to prevent duplicate ingestion
2. Extracts text using the Claude API (claude-haiku-4-5), which handles PDFs, slide decks, and printed emails
3. Splits the text into chunks at two sizes simultaneously:
   - **Small chunks (~200 tokens)**: for precise, factual retrieval ("what is the late work penalty?")
   - **Large chunks (~900 tokens)**: for contextual retrieval ("why did we change the late work policy?")
4. Embeds every chunk using OpenAI text-embedding-3-small (1536 dimensions)
5. Stores everything in PostgreSQL (Supabase) with the pgvector extension

Both chunk sizes are stored permanently. The raw extracted text is also stored, so documents can be re-embedded with better models in the future without reprocessing the original PDFs.

### Database

- **Host**: Supabase (PostgreSQL 17 + pgvector)
- **Project**: ohs-memory (https://qawqovyqnvlcyuxezmrp.supabase.co)
- **Tables**: `documents` (one row per source file) and `chunks` (many rows per document, both sizes)
- **Search**: HNSW vector index for cosine similarity search
- **Filters**: subject, school_year, teacher, doc_type, unit

### MCP Server

An MCP (Model Context Protocol) server wraps the database search and exposes it as tools for AI clients. It runs locally on a teacher's machine via stdio transport — no hosting required. The AI client (Claude Code, Claude Desktop) launches the Python process, which connects to Supabase over the internet.

Tools exposed:
- `search_decisions(query, subject, school_year, doc_type, teacher, chunk_size, limit)` — semantic search
- `list_documents(subject, school_year, doc_type)` — browse the archive
- `get_corpus_stats()` — understand coverage and gaps

## What Gets Ingested

Priority document types, in order of importance:

1. **Team meeting notes** — decisions made, policies adopted, problems discussed
2. **Policy documents** — late work, phones, grading, behavior expectations
3. **Lesson plans** — what we teach, in what order, with what materials
4. **Emails to the team** — decisions communicated over email
5. **This documentation** — what the system itself is and how it works

## Document Metadata

Every ingested document is tagged with:
- `subject` — the academic subject or "All-Staff"
- `school_year` — e.g. "2025-26"
- `teacher` — the author or responsible teacher
- `doc_type` — lesson_plan, meeting_notes, policy, email, or other
- `unit` — the instructional unit, if applicable
- `notes` — any free-form context

## Running the Ingestion Script

```bash
cd ohs-memory/

# Single file (prompts for metadata interactively)
python ingest.py path/to/document.pdf

# Single file with metadata pre-filled
python ingest.py notes.pdf --subject "All-Staff" --year 2025-26 --type meeting_notes

# Batch ingest a folder
python ingest.py ./docs/
```

## Testing Search

```bash
# Basic search
python search.py "how do we handle late work?"

# Compare small vs large chunks side by side (the main tuning tool)
python search.py "phone policy" --compare

# Filter by subject and year
python search.py "unit 2 assignments" --subject Economics --year 2025-26

# List all ingested documents
python search.py --list
```

## Environment Variables Required

```
ANTHROPIC_API_KEY   — PDF text extraction via Claude API
OPENAI_API_KEY      — Embeddings via text-embedding-3-small
DATABASE_URL        — PostgreSQL connection to Supabase (password %-encoded)
SUPABASE_URL        — https://qawqovyqnvlcyuxezmrp.supabase.co
SUPABASE_ANON_KEY   — Supabase publishable key (read-only search)
```

## MCP Server Configuration (Claude Desktop)

Add to `%APPDATA%\Claude\claude_desktop_config.json` on Windows:

```json
{
  "mcpServers": {
    "ohs-memory": {
      "command": "python",
      "args": ["C:/Users/johnw/Documents/GitHub/AI/ohs-memory/mcp_server.py"],
      "env": {
        "OPENAI_API_KEY": "...",
        "SUPABASE_URL": "https://qawqovyqnvlcyuxezmrp.supabase.co",
        "SUPABASE_ANON_KEY": "..."
      }
    }
  }
}
```

## Technology Stack

- **Python 3.11** — ingestion script, MCP server, CLI search tool
- **Anthropic Claude API** — PDF text extraction (claude-haiku-4-5)
- **OpenAI API** — text-embedding-3-small (1536 dimensions)
- **PostgreSQL 17 + pgvector** — vector database hosted on Supabase
- **MCP Python SDK** — FastMCP server framework
- **tiktoken** — token counting for chunking

## Future-Proofing

The raw extracted text from every document is stored in the `raw_text` column of the `documents` table. When better embedding models become available, the entire corpus can be re-embedded without reprocessing the original PDF files:

```sql
-- Delete all embeddings (keeps raw_text and document metadata)
DELETE FROM chunks;
```

Then update the embedding model name in `ingest.py` and `mcp_server.py`, and re-run ingestion against the stored raw text.

## Cost

- Supabase free tier: $0/month (up to 500MB)
- OpenAI embeddings: approximately $0.02 per 1 million tokens (negligible)
- Anthropic PDF extraction: approximately $0.25 per 1 million input tokens
- Total ongoing cost for typical school use: under $5/year

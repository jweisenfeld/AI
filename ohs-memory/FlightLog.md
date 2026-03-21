# FlightLog: The Orion High School Organizational Memory System

## What Is FlightLog?

FlightLog is the institutional memory system for Orion High School (OHS), a small, choice, public, rural, non-traditional high school in Pasco, Washington, operated by Pasco School District #1. FlightLog is both a noun and a verb: staff can *check the FlightLog* to find a policy or decision, and leadership can *FlightLog* a document or email thread to make it permanently searchable by the entire organization.

The purpose of FlightLog is to make the hard-won knowledge of Orion High School—captured in emails, planning documents, slide decks, schedules, and meeting notes—available to anyone on staff at any time, through a simple question asked in plain English.

FlightLog is especially designed to onboard new staff. When a new teacher, counselor, or administrator joins Orion High School and asks "How does Advisory work here?" or "What is the field trip policy?" or "What happened with the fart spray incident?", FlightLog can synthesize an answer from the actual documents and email threads where those decisions were made—with citations to the original sources.

The name FlightLog is intentional. Like a pilot's flight log, it is a running record of where we have been, what decisions were made, and why. It grows with the school. It does not forget.

---

## Part One: The Simple Story

### The Problem FlightLog Solves

Orion High School opened in 2024. In its first year, enormous amounts of institutional knowledge were created and exchanged: planning emails, policy decisions, curriculum choices, bell schedule debates, staff agreements, cultural norms, and hundreds of operational details. Most of this knowledge lived in two places: people's email inboxes and a SharePoint document library. Neither is easy to search well. More importantly, neither is easy for a *new* person to search at all—they don't know what they're looking for or what words were used.

When a founding staff member eventually leaves, that knowledge leaves with them. FlightLog prevents that.

### What FlightLog Does

FlightLog ingests documents and emails, breaks them into searchable pieces, and stores them in a database that understands meaning—not just keywords. When a staff member asks a question, FlightLog finds the most relevant pieces across all ingested material and uses an AI (Claude, made by Anthropic) to synthesize a plain-English answer with citations.

### What Has Been Indexed (as of March 2026)

FlightLog currently contains:

- **1,210 emails** from the Orion Planning Team email group (the primary communication channel for OHS leadership and planning staff), dating from the school's founding through the current school year
- **773 documents** across **176 folders** from the Orion Planning Team SharePoint document library, including lesson plans, bell schedules, curriculum materials, policy documents, meeting slides, committee notes, and operational records

This represents the near-complete institutional record of Orion High School's first two years of existence.

### How Staff Use FlightLog

The web interface is at **orionhs.us/flightlog**. Any staff member can visit that URL and type a question in plain English. FlightLog returns a synthesized answer with numbered source citations, and an option to view the raw source chunks.

Examples of questions FlightLog can answer:
- *"What is the Advisory curriculum and who coordinates it?"*
- *"What did we decide about bell schedules for advisory on Thursdays?"*
- *"What is the Orion High School approach to restorative practices?"*
- *"What is the 25-26 SY Tier Days structure?"*
- *"Who handles sub plans and how are they organized?"*

---

## Part Two: The Technical Story

### Architecture

FlightLog is a Retrieval-Augmented Generation (RAG) system. It has four layers:

**Layer 1 — Data Store**
A PostgreSQL database hosted on Supabase, with the `pgvector` extension enabled. Two tables: `documents` (one row per ingested file) and `chunks` (one row per text chunk, with a 1536-dimension embedding vector). The embedding model is OpenAI `text-embedding-3-small`.

**Layer 2 — Ingestion Pipeline**
A Python command-line tool (`ingest.py`) that accepts files or folders and supports the following formats: `.pdf`, `.docx`, `.pptx`, `.xlsx`, `.md`, `.txt`, `.msg`. For each file, it extracts text, chunks it at two granularities (small: ~500 tokens, large: ~1500 tokens), generates embeddings via the OpenAI API, and stores everything in Supabase. A SHA-256 hash of each file prevents duplicate ingestion. An `--update` flag handles changed files safely (new record committed before old is deleted).

PDF text extraction uses the Claude API (claude-haiku-4-5) for high-quality extraction including scanned documents; files over 20 MB fall back to `pdfplumber`. Outlook `.msg` files are extracted using the `extract-msg` library, with email headers preserved as metadata and supported attachments (PDF, DOCX) extracted inline.

**Layer 3 — Search and Synthesis Web Application**
A PHP API proxy (`search-proxy.php`) hosted on psd1.net receives search queries from the browser, calls Supabase's vector similarity search via REST API, then calls the Anthropic API (claude-haiku-4-5) to synthesize a plain-prose answer from the top results. The web interface (`index.html`) is a clean single-page app at `psd1.net/ohs-search`. A diagnostic raw-search view is available at `psd1.net/ohs-search/index0.html`. Credentials are managed via a `.secrets` file above `public_html` on the server, consistent with the project's other PHP applications.

**Layer 4 — MCP Server (planned)**
An MCP (Model Context Protocol) server will expose FlightLog as a tool available directly inside Claude conversations—in Claude Desktop, Claude Code, and any other MCP-compatible AI client. Staff will be able to ask FlightLog questions directly from within Claude without opening a browser. The server will expose a `search_ohs_memory(query, filters)` tool, wrapping the same vector search logic already in `search-proxy.php`, implemented in Python using Anthropic's `mcp` SDK. Once configured, FlightLog becomes a permanent background capability of Claude for anyone at OHS who adds it to their Claude Desktop config.

### Data Sources and Ingestion Details

**Emails:** The Orion Planning Team M365 Group inbox was exported to `.msg` files using an Outlook VBA macro (`export_outlook_folder.vba`) that exports whatever folder is currently selected in Outlook. Emails are tagged with `doc_type: email`, `unit: Orion Planning Team`, and school year is auto-detected from the sent date (pre-opening / 2024-25 / 2025-26).

**SharePoint documents:** The Orion Planning Team SharePoint library is synced locally via OneDrive to `C:\Users\johnw\Pasco School District #1\Orion Planning Team - Documents\`. The ingest pipeline runs against this local path recursively. The top-level subfolder name (e.g., "Bell Schedules", "Advisory Lessons", "Culture") is automatically used as the `unit` metadata tag for each document.

**Update cadence:** Re-running ingest on either source is safe at any time. Unchanged files are skipped in milliseconds. Changed files are replaced (with `--update` flag). New files are added. The system is designed for periodic refresh as new documents arrive.

### Repository

The code lives in the `AI` repository on GitHub (`jweisenfeld/AI`), in the following folders:
- `ohs-search/` — web interface and PHP API proxy
- `ohs-memory/` — ingestion pipeline (`ingest.py`), VBA export macro, credential template

---

## The Vision

FlightLog is a prototype of what every small school could have: a living, searchable institutional record that grows with the organization and survives staff turnover.

Orion High School is non-traditional by design. It attracts staff who are thoughtful, collaborative, and willing to document their work. That culture generates exactly the kind of rich, textured record that makes a system like FlightLog valuable. The school's planning emails are not just logistics—they contain reasoning, values, and context that would take a new staff member months to absorb through osmosis.

FlightLog makes that absorption possible in hours.

Future directions include:
- MCP server integration (next priority)
- Scheduled ingestion to automatically pick up new SharePoint documents and emails
- Additional data sources: meeting transcripts, the student handbook, board policies, the master agreement
- A staff-facing "Document Archive" browsing view (partially implemented in the current UI)
- Potential expansion to other schools in Pasco School District #1

---

## How to Build FlightLog for Your School

The following prompt, given to Claude, will reproduce everything described in this document:

---

*"I want to build an institutional memory system for my school called FlightLog. Here is what it needs to do: ingest documents and emails from our school's SharePoint and email group, store them as vector embeddings in a Supabase/pgvector database, and provide a web interface where staff can ask questions in plain English and get AI-synthesized answers with source citations. I also want an MCP server so staff can query it directly from Claude Desktop.*

*The tech stack should be: Python for ingestion (supporting .pdf, .docx, .pptx, .xlsx, .msg, .txt, .md), OpenAI text-embedding-3-small for embeddings, Supabase with pgvector for storage, PHP for the web API proxy (hosted on cPanel), and Claude Haiku for synthesis. Credentials should be stored in a .secrets file above public_html, not committed to git.*

*The ingestion pipeline needs: SHA-256 hash deduplication, an --update flag that replaces changed files safely (commit new before deleting old), auto-detection of school year from email sent dates, inline attachment extraction from .msg files, automatic unit tagging from folder names, PDF extraction via Claude API with pdfplumber fallback for large files, a --limit flag for test runs, and a --log flag for error logging.*

*Please design the full system, starting with the Supabase schema, then the ingestion pipeline, then the PHP search proxy, then the web UI, and finally the MCP server."*

---

*FlightLog. We log our flights so we can find our way back.*

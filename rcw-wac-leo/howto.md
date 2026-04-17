# LEO Legal Reference — How It Works

A derivative of the [rcw-wac](../rcw-wac/) WA Law RAG chat, tuned for law enforcement officers, supervisors, and administrators. Same vector database and retrieval pipeline — different system prompt, color scheme, and example questions.

**Live demo:** https://psd1.net/rcw-wac-leo/  
**Parent project:** https://psd1.net/rcw-wac/ (general public version)

---

## What Makes This a Derivative

| | rcw-wac (general) | rcw-wac-leo (LEO) |
|--|---|---|
| **Audience** | General public, educators, families | Law enforcement officers and supervisors |
| **System prompt** | Education/family law focus | Use of force, arrest authority, officer certification |
| **Color scheme** | Washington blue (#1a3a5c) | Dark navy (#0d2035) + badge gold (#c9a227) |
| **Example chips** | IEP, suspension, public records | Deadly force, duty to intervene, DV mandatory arrest |
| **Max output** | 1,200 tokens | 1,500 tokens |
| **Corpus defaults** | All (RCW/WAC/USC/CFR) | All (RCW/WAC/USC/CFR) |

Everything else is shared: the PHP proxy class, Supabase project, embedding model, Claude model, and SSE streaming protocol.

---

## Architecture

Identical to the parent project. See [rcw-wac/howto.md](../rcw-wac/howto.md) for the full pipeline diagram and tech stack.

```
User query
  └─ api-proxy.php (this folder)
       ├─ require_once ../rcw-wac/src/RcwWacProxy.php   ← shared class
       ├─ embed → search Supabase (same DB as rcw-wac)
       └─ stream Claude Haiku with LEO system prompt
```

There is no `src/` or `ingestion/` folder here. This project intentionally references the parent's class and database.

---

## Files

| File | Role |
|------|------|
| `index.html` | LEO-themed chat UI |
| `api-proxy.php` | LEO system prompt + routes (?stream=1, ?stats=1, ?prompt=1, ?catalog=) |
| `howto.md` | This file |

**Shared (from parent):**
- `../rcw-wac/src/RcwWacProxy.php` — proxy class
- Same Supabase project and `rcwkey.php` secrets file

---

## Key RCW/WAC Areas in the System Prompt

| Area | Sections |
|------|---------|
| Use of force | RCW 10.116, RCW 10.120, RCW 9A.16 |
| Arrest authority | RCW 10.31, RCW 10.31.100 (DV mandatory arrest) |
| Criminal code | RCW Title 9A, RCW Title 9 |
| Officer certification | RCW 43.101, WAC 139 (CJTC) |
| Municipal authority | RCW Title 35A (code cities), RCW Title 36 (counties) |
| Traffic | RCW Title 46 |
| Public records | RCW 42.56 |

Federal constitutional standards (4th, 5th, 14th Amendment) are noted even when specific case law is not in the retrieved sections.

---

## About Modal

The UI includes an **About** button that exposes:
- **Query pipeline** diagram (embed → vector+BM25 → Claude)
- **Live DB stats** — chunk counts per corpus fetched from `?stats=1`
- **Full system prompt** — scrollable with copy button, so users can inspect exactly what Claude is told
- **Technical notes** — model names, chunk size, retrieval method, repo location

Clicking any corpus card (RCW/WAC/USC/CFR) in the stats grid opens a **catalog modal** listing every ingested title and chapter with chunk counts. Also triggered by clicking corpus badges on source cards in chat.

---

## Regeneration Prompt

Paste this into a fresh Claude conversation to rebuild this derivative from scratch (assumes the parent `rcw-wac/` project already exists):

```
I have an existing legal RAG project at rcw-wac/ with this structure:
- rcw-wac/src/RcwWacProxy.php — shared proxy class (getEmbedding, searchSupabase, buildContext, logQuery)
- rcw-wac/api-proxy.php — general public version
- rcw-wac/index.html — general public UI (Washington blue theme)
- Supabase backend with rcw_wac_chunks table (corpus: rcw|wac|usc|cfr)
- Secrets at ../.secrets/rcwkey.php

Create a derivative called rcw-wac-leo/ tuned for law enforcement officers.
The folder should have no src/ or ingestion/ subfolder — it references the parent's class.

FILES TO CREATE:

1. rcw-wac-leo/index.html
   Color scheme: dark navy (#0d2035) primary, badge gold (#c9a227) accent.
   Shield emoji 🛡️ in header badge and welcome screen.
   Title: "LEO Legal Reference"
   Subtitle: "RCW · WAC · USC · CFR — Washington & Federal Law"
   Corpus toggle: All / State / RCW / WAC / Federal
   Example chips (mix of English and Spanish):
   - "When is deadly force lawful under Washington law?"
   - "De-escalation requirements before using force"
   - "Duty to intervene — what does RCW 10.120.020 require?"
   - "Warrantless arrest authority under RCW 10.31"
   - "DV mandatory arrest — what triggers it?"
   - "Public records exemptions for law enforcement"
   - "¿Cuándo puede la policía registrar un vehículo sin orden judicial?"
   - "Un menor sirviendo alcohol en una fiesta privada — ¿qué RCW aplica?"
   Disclaimer: "General legal information only — not legal advice. Consult your department
   legal advisor, city attorney, or prosecutor for specific situations."

   Include an About modal (triggered by header button) with:
   - Query pipeline diagram (plain text boxes with arrows)
   - Live DB stats grid fetched from ?stats=1 (RCW/WAC/USC/CFR cards, clickable)
   - Full system prompt display (scrollable monospace, copy button) fetched from ?prompt=1
   - Technical notes (model names, chunk size, retrieval config, repo)
   Clicking a corpus stat card opens a catalog modal listing all ingested
   titles/chapters/chunk-counts fetched from ?catalog={corpus}.

2. rcw-wac-leo/api-proxy.php
   require_once __DIR__ . '/../rcw-wac/src/RcwWacProxy.php'
   Secrets: same path (../.secrets/rcwkey.php)

   Routes:
   - ?stats=1    → proxy rcw_wac_stats() RPC to Supabase, return JSON
   - ?prompt=1   → return json_encode(['prompt' => SYSTEM_PROMPT])
   - ?catalog=X  → proxy rcw_wac_catalog(X) RPC, accepts rcw|wac|usc|cfr
   - ?stream=test → SSE sanity check (no API calls)
   - ?stream=1   → main: embed → search (top 8, corpus filter) → SSE sources+text+meta

   MAX_OUTPUT_TOKENS = 1500

   SYSTEM PROMPT (law enforcement focus):
   - Audience: WA law enforcement officers, supervisors, administrators
   - Sources: RCW, WAC, USC, CFR with explanations of each
   - Key areas: use of force (RCW 10.116/10.120/9A.16), criminal procedure (RCW Title 10),
     criminal code (RCW 9A/9), arrest authority (RCW 10.31/10.31.100 DV),
     officer certification (RCW 43.101/WAC 139 CJTC), municipal authority (RCW 35A/36),
     traffic (RCW 46), public records (RCW 42.56)
   - Instructions: cite every section, distinguish statute from rule,
     apply RCW 10.116/10.120 framework for use-of-force questions,
     note 4th/5th/14th Amendment standards when applicable,
     plain language with terms defined on first use,
     note gaps when retrieved sections only partially answer the question,
     no speculation beyond provided context
   - Disclaimer: general legal information only, not legal advice
   - Context block: {CONTEXT} placeholder replaced at runtime

The LEO project shares the same Supabase database as rcw-wac. No ingestion needed.
Both projects use corpus filter values: rcw, wac, usc, cfr, state (rcw+wac), federal (usc+cfr).
```

---

## Creating Your Own Derivative

This pattern generalizes to any legal audience. To create a new derivative:

1. Copy `rcw-wac-leo/` to a new folder (e.g., `rcw-wac-edu/` for educators)
2. Update `api-proxy.php`: change `SYSTEM_PROMPT` for your audience; adjust `MAX_OUTPUT_TOKENS`
3. Update `index.html`: change color scheme, header text, example chips, disclaimer
4. The `require_once` path stays the same: `/../rcw-wac/src/RcwWacProxy.php`
5. No ingestion needed — all derivatives share the same Supabase database

The system prompt is the highest-leverage change. Telling Claude who the audience is, what they care about, and which sections are most relevant dramatically improves answer quality even on the same retrieved chunks.

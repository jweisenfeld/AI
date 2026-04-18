# rcw-wac-leo/ — CLAUDE.md

## What This Is

**LEO Legal Reference** — a law enforcement–oriented derivative of `rcw-wac/`. Same RAG pipeline,
same Supabase backend, same ingested corpus. Tuned for Washington State law enforcement officers,
supervisors, and administrators.

Target audience: Pasco Police Department (and potentially other WA law enforcement agencies).

## Relationship to rcw-wac/

| Aspect | rcw-wac/ | rcw-wac-leo/ |
|--------|----------|--------------|
| Audience | General public, educators, families | Law enforcement officers/admins |
| System prompt | General WA law + federal | LEO focus: use of force, arrest, DV, public records |
| UI theme | WA blue (#1a3a5c) | Midnight navy (#0d2035) with gold accent (#c9a227) |
| Header badge | ⚖️ | 🛡️ |
| Example chips | IEP/504, public records, federal interaction | Use of force, de-escalation, warrantless arrest, DV |
| Spanish chips | IEP/autism, cyberbullying | Vehicle search, minor serving alcohol |
| Shared class | Uses `../rcw-wac/src/RcwWacProxy.php` | Same |
| Shared database | Same Supabase project | Same |

## Files

| File | Role |
|------|------|
| `index.html` | LEO-themed chat UI. Gold accent. About modal. Corpus catalog. |
| `api-proxy.php` | LEO entry point. Loads LEO system prompt. Shares `RcwWacProxy` from `../rcw-wac/src/`. |
| `howto.md` | Architecture docs + LEO regeneration prompt + "Creating Your Own Derivative" section. |

**Not duplicated here** (shared from rcw-wac/):
- `src/RcwWacProxy.php` — core proxy class
- `ingestion/` — all ingestion tooling
- `ingestion/schema.sql` — Supabase schema

## LEO System Prompt Focus Areas

The `api-proxy.php` system prompt is tuned for law enforcement:

- **Use of force**: RCW 10.116 (use of force standards), RCW 10.120 (officer intervention and de-escalation), RCW 9A.16 (lawful use of force defenses)
- **Criminal procedure**: RCW Title 10 (arrest, search and seizure, warrants, bail)
- **Criminal code**: RCW Title 9A (Washington Criminal Code — assault, theft, weapons), RCW Title 9
- **Officer authority and duties**: RCW 10.31 (arrest authority), RCW 10.31.100 (DV mandatory arrest)
- **Officer certification**: RCW 43.101, WAC 139 (CJTC — training and certification requirements)
- **Municipal police authority**: RCW Title 35A (code cities — applicable to Pasco), RCW Title 36 (counties)
- **Traffic enforcement**: RCW Title 46
- **Public records**: RCW 42.56 (disclosure obligations and exemptions for law enforcement records)
- **Federal**: 4th, 5th, 14th Amendment framework noted when applicable; federal use-of-force standards

Prompt instructs Claude to: apply the 2021 WA police reform framework (RCW 10.116/10.120), cite every section, distinguish statute from rule, note constitutional standards, not speculate beyond retrieved sections.

Disclaimer: "This tool provides general legal information only — not legal advice. For specific operational or legal situations, officers should consult their department legal advisor, city attorney, or the prosecuting attorney's office."

## API Routes

Same routes as rcw-wac/api-proxy.php:

| Route | Description |
|-------|-------------|
| `POST ?stream=1` | Main query: embed → search → stream Claude |
| `GET ?stats=1` | Live DB chunk/title counts (`rcw_wac_stats()` RPC) |
| `GET ?prompt=1` | Returns LEO system prompt as JSON |
| `GET ?catalog=rcw\|wac\|usc\|cfr` | Ingested titles/chapters (`rcw_wac_catalog()` RPC) |
| `GET ?stream=test` | Streaming sanity check |

## About Modal

The About button (top-right of header) opens a modal with:
1. **Query pipeline diagram** — 4-step RAG flow visualization
2. **Live DB stats** — per-corpus chunk/title counts from `?stats=1`; clicking a stat card opens the corpus catalog for that corpus
3. **System prompt inspector** — exact LEO prompt with Copy button
4. **Technical notes** — model, embedding, retrieval, corpus sources, repo location

Corpus badges on source cards (in chat) also open the catalog for that corpus.

## Deployment

Upload `index.html`, `api-proxy.php` to `psd1.net/rcw-wac-leo/` via cPanel.
The `src/` folder is served from `../rcw-wac/src/` via `require_once` — no separate copy needed.

Verify at `https://psd1.net/rcw-wac-leo/`

## Database

Same Supabase project as rcw-wac/ (`ogcmyupxiykyngzeftwy.supabase.co`). No separate setup needed.
See `rcw-wac/CLAUDE.md` for full database documentation (schema, role timeouts, Micro Compute upgrade).

## Model

`claude-haiku-4-5-20251001`, `MAX_OUTPUT_TOKENS = 2000`.
When Claude hits `max_tokens`, the UI shows "⚠ response clipped" and a "Continue response →" button.

## Secrets

Same `../.secrets/rcwkey.php` as rcw-wac/. No separate secrets file.

## Corpus Filter Values

Accepted values for `corpus` in POST body: `rcw`, `wac`, `usc`, `cfr`, `state` (rcw+wac), `federal` (usc+cfr), `null` (all).

## SSE Event Protocol

Identical to rcw-wac/:
```
data: {"sources": [{section_id, section_heading, corpus, source_url, similarity_pct}, ...]}
data: {"text": "...streaming delta..."}
data: {"meta": {inTokens, outTokens, cachedTokens, cacheWrite, resultCount, stopReason}}
data: [DONE]
```

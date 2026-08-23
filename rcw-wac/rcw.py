#!/usr/bin/env python3
"""
WA Law Assistant — CLI client
  python rcw.py "your question"          # single query
  python rcw.py                          # interactive (multi-turn)
  python rcw.py -c rcw "question"        # restrict to RCW only
  python rcw.py --no-sources "question"  # hide source cards
  python rcw.py --plain "question"       # no rich formatting, plain text

Requirements: pip install httpx rich
"""

import sys
import json
import argparse
import httpx
from rich.console import Console
from rich.markdown import Markdown
from rich.live import Live
from rich.panel import Panel
from rich.table import Table
from rich.text import Text

PROXY_URL = "https://psd1.net/rcw-wac/api-proxy.php"

CORPUS_COLORS = {"rcw": "blue", "wac": "green", "usc": "red", "cfr": "magenta"}

console = Console()


def stream_query(query: str, corpus: str = "both", history: list = None, show_sources: bool = True):
    if history is None:
        history = []

    payload = {
        "query": query,
        "corpus": None if corpus == "both" else corpus,
        "messages": history[-12:],
    }

    full_text = ""
    sources = []
    meta = {}

    try:
        with httpx.Client(timeout=90.0) as client:
            with client.stream(
                "POST",
                f"{PROXY_URL}?stream=1",
                json=payload,
                headers={"Content-Type": "application/json"},
            ) as resp:
                if resp.status_code != 200:
                    console.print(f"[red]HTTP {resp.status_code}[/red]")
                    return None, []

                buf = ""
                text_started = False

                with Live(console=console, refresh_per_second=12, vertical_overflow="visible") as live:
                    live.update(Text("Searching law database…", style="italic dim"))

                    for chunk in resp.iter_bytes():
                        buf += chunk.decode("utf-8", errors="replace")

                        while "\n" in buf:
                            line, buf = buf.split("\n", 1)
                            line = line.rstrip("\r")

                            # SSE comment (heartbeat / init) — skip
                            if line.startswith(":"):
                                continue
                            if not line.startswith("data: "):
                                continue

                            raw = line[6:]
                            if raw == "[DONE]":
                                break

                            try:
                                evt = json.loads(raw)
                            except json.JSONDecodeError:
                                continue

                            if evt.get("error"):
                                live.update(Text(f"Error: {evt['error']}", style="bold red"))
                                return None, []

                            if "sources" in evt:
                                sources = evt["sources"]

                            if "text" in evt:
                                if not text_started:
                                    text_started = True
                                full_text += evt["text"]
                                live.update(Markdown(full_text))

                            if "meta" in evt:
                                meta = evt["meta"]

    except httpx.ConnectError as e:
        console.print(f"[red]Connection error:[/red] {e}")
        return None, []
    except httpx.TimeoutException:
        console.print("[red]Request timed out.[/red]")
        return None, []

    # Sources table
    if show_sources and sources:
        table = Table(show_header=True, header_style="bold", padding=(0, 1), expand=False)
        table.add_column("", style="bold", no_wrap=True)      # corpus badge
        table.add_column("Section", no_wrap=True)
        table.add_column("Heading")
        table.add_column("Match", justify="right", no_wrap=True)

        for s in sources:
            corp = s.get("corpus", "")
            label = s.get("corpus_label", corp.upper())
            color = CORPUS_COLORS.get(corp, "white")
            sim = f"{s['similarity_pct']}%" if s.get("similarity_pct") is not None else ""
            heading = s.get("section_heading") or s.get("chapter_name") or ""
            table.add_row(
                f"[{color}]{label}[/{color}]",
                s.get("section_id", ""),
                heading,
                f"[dim]{sim}[/dim]",
            )

        console.print()
        console.rule("[dim]Sources[/dim]")
        console.print(table)

    # Token meta footer
    if meta:
        parts = []
        if meta.get("resultCount") is not None:
            parts.append(f"{meta['resultCount']} sections retrieved")
        if meta.get("outTokens"):
            parts.append(f"{meta['outTokens']} tok out")
        if meta.get("inTokens"):
            parts.append(f"{meta['inTokens']} in")
        if meta.get("cachedTokens"):
            parts.append(f"{meta['cachedTokens']} cached")
        if meta.get("stopReason") == "max_tokens":
            parts.append("[yellow]⚠ clipped — ask 'please continue'[/yellow]")
        if parts:
            console.print(f"\n[dim]{' · '.join(parts)}[/dim]")

    return full_text, sources


def main():
    parser = argparse.ArgumentParser(
        description="WA Law Assistant CLI — search RCW, WAC, USC, CFR",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument("query", nargs="*", help="Question (omit for interactive mode)")
    parser.add_argument(
        "--corpus", "-c",
        choices=["both", "state", "federal", "rcw", "wac", "usc", "cfr"],
        default="both",
        metavar="CORPUS",
        help="Restrict search: both|state|federal|rcw|wac|usc|cfr (default: both)",
    )
    parser.add_argument("--no-sources", action="store_true", help="Hide source citations")
    parser.add_argument("--plain", action="store_true", help="Plain text output (no colors)")
    args = parser.parse_args()

    show_sources = not args.no_sources

    global console
    if args.plain:
        console = Console(highlight=False, markup=False, no_color=True)

    history: list = []

    if args.query:
        query = " ".join(args.query)
        console.print(f"\n[bold blue]You:[/bold blue] {query}\n")
        full_text, _ = stream_query(query, args.corpus, history, show_sources)
        if full_text:
            history.append({"role": "user", "content": query})
            history.append({"role": "assistant", "content": full_text})
    else:
        # Interactive multi-turn mode
        console.print(
            Panel(
                "[bold]WA Law Assistant[/bold] — RCW · WAC · USC · CFR\n"
                f"[dim]Corpus: {args.corpus} | Enter to send | Shift+Enter for newline | quit/exit to quit[/dim]",
                border_style="blue",
                padding=(0, 1),
            )
        )

        while True:
            try:
                query = console.input("\n[bold blue]You:[/bold blue] ").strip()
            except (EOFError, KeyboardInterrupt):
                console.print("\n[dim]Goodbye.[/dim]")
                break

            if not query:
                continue
            if query.lower() in ("quit", "exit", "q", ":q"):
                console.print("[dim]Goodbye.[/dim]")
                break
            if query.lower() in ("clear", "/clear"):
                history.clear()
                console.clear()
                console.print("[dim]Conversation history cleared.[/dim]")
                continue

            console.print()
            full_text, _ = stream_query(query, args.corpus, history, show_sources)
            if full_text:
                history.append({"role": "user", "content": query})
                history.append({"role": "assistant", "content": full_text})


if __name__ == "__main__":
    main()

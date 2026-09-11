#!/usr/bin/env python3
"""
Measure whether psd1.net actually streams, or buffers and dumps at the end.

Pairs with stream-test.php. That script writes one SSE event per second for
ten seconds; this one records when each event ARRIVES here. If the arrivals
are spread roughly one second apart, Server-Sent Events survive the hosting
stack and a streaming rewrite of api-proxy.php is worth doing. If they all
land together at the ten-second mark, the host buffered the whole response
and streaming would change nothing a student can see.

Runs three variants, because the usual culprits are separable:
  1. plain              — the honest baseline
  2. no compression     — Accept-Encoding: identity, in case mod_deflate buffers
  3. padded             — ?pad=1, in case a handler won't flush a small buffer

Costs nothing and needs no credentials: stream-test.php calls no model API.

    python stream-test.py
    python stream-test.py --url https://psd1.net/claude/stream-test.php
"""

import argparse
import json
import sys
import time

try:
    import requests
except ImportError:
    sys.exit("This script needs `requests`:  pip install requests")


# Windows consoles default to cp1252 and raise UnicodeEncodeError on arrows,
# dashes and box characters. Never let a formatting character kill a report.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except (AttributeError, ValueError):
    pass

DEFAULT_URL = "https://psd1.net/claude/stream-test.php"

# psd1.net's ModSecurity rejects the default python-requests User-Agent (406).
BROWSER_UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/124.0.0.0 Safari/537.36"
)


def run_variant(url, label, headers, params):
    """Fetch the SSE endpoint and record the arrival time of each event."""
    print(f"\n--- {label} ---")
    arrivals = []
    started = time.time()

    try:
        with requests.get(url, headers=headers, params=params, stream=True, timeout=60) as resp:
            if resp.status_code != 200:
                print(f"  HTTP {resp.status_code} — {resp.text[:200]}")
                return None
            encoding = resp.headers.get("Content-Encoding", "(none)")
            print(f"  HTTP 200 | Content-Encoding: {encoding} | "
                  f"X-Accel-Buffering: {resp.headers.get('X-Accel-Buffering', '(unset)')}")

            for raw in resp.iter_lines(chunk_size=1, decode_unicode=True):
                if raw and raw.startswith("data:"):
                    at = time.time() - started
                    try:
                        payload = json.loads(raw[5:].strip())
                    except ValueError:
                        payload = {}
                    if "n" in payload:
                        arrivals.append((payload["n"], at, payload.get("server_elapsed")))
    except requests.RequestException as exc:
        print(f"  request failed: {exc}")
        return None

    if not arrivals:
        print("  no events received")
        return None

    for n, at, server_at in arrivals:
        print(f"  event {n:2d}  arrived {at:6.2f}s   (server wrote it at {server_at}s)")

    first, last = arrivals[0][1], arrivals[-1][1]
    spread = last - first
    print(f"  first {first:.2f}s | last {last:.2f}s | spread {spread:.2f}s")
    return {"label": label, "first": first, "last": last, "spread": spread, "count": len(arrivals)}


def main():
    parser = argparse.ArgumentParser(description="Test whether SSE streaming survives the host.")
    parser.add_argument("--url", default=DEFAULT_URL, help="stream-test.php URL")
    args = parser.parse_args()

    print(f"Testing {args.url}")
    print("stream-test.php writes 1 event per second for 10 seconds.")

    variants = [
        ("plain", {"User-Agent": BROWSER_UA, "Accept": "text/event-stream"}, None),
        ("no compression", {"User-Agent": BROWSER_UA, "Accept": "text/event-stream",
                            "Accept-Encoding": "identity"}, None),
        ("padded (?pad=1)", {"User-Agent": BROWSER_UA, "Accept": "text/event-stream",
                             "Accept-Encoding": "identity"}, {"pad": "1"}),
    ]

    results = [r for r in (run_variant(args.url, *v) for v in variants) if r]

    print("\n" + "=" * 68)
    if not results:
        print("VERDICT: no variant returned events — check the URL and that")
        print("stream-test.php is uploaded.")
        return 1

    # The server takes ~9s between its first and last write. If arrivals are
    # spread over a similar window, nothing buffered them.
    streaming = [r for r in results if r["spread"] > 5.0]
    partial = [r for r in results if 1.0 < r["spread"] <= 5.0]

    for r in results:
        if r["spread"] > 5.0:
            verdict = "STREAMS — events arrived as written"
        elif r["spread"] > 1.0:
            verdict = "PARTIAL — some chunking, but not clean streaming"
        else:
            verdict = "BUFFERED — everything arrived at once"
        print(f"  {r['label']:18s} spread {r['spread']:5.2f}s over {r['count']} events  →  {verdict}")

    print()
    if streaming:
        print("VERDICT: streaming works on this host" +
              (f" (via '{streaming[0]['label']}')" if streaming[0]["label"] != "plain" else ""))
        print("A streaming rewrite of api-proxy.php will actually reach students.")
    elif partial:
        print("VERDICT: partial buffering. Streaming would help but not fully —")
        print("worth checking gzip/handler settings before committing to the rewrite.")
    else:
        print("VERDICT: the host buffers the whole response.")
        print("Streaming would NOT change what a student sees. Don't do the rewrite;")
        print("keep the timeout raised and ask models for smaller pieces instead.")
    print("=" * 68)
    return 0


if __name__ == "__main__":
    sys.exit(main())

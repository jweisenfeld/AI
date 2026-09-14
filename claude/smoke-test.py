#!/usr/bin/env python3
"""
Smoke-test every model tier in the student chatbot, end to end.

Sends one short "write a function in a code block" prompt to each tier in
model_config.json through the DEPLOYED api-proxy.php, then reports what came
back: which model actually answered, whether it finished or hit the token cap,
whether the answer was empty, and whether a fenced code block survived intact
with its indentation. Exit code is 0 only if every tier passed.

This tests the deployed site, not your working copy — upload your changes
first, then run this to confirm students will get what you expect.

    python smoke-test.py                 # all tiers, streaming (what students get)
    python smoke-test.py --no-stream     # the non-streaming path instead
    python smoke-test.py --tier dspro    # just one tier
    python smoke-test.py --with-image    # also send a test image to vision tiers
    python smoke-test.py --verbose       # print each answer in full

STREAMING is on by default because index.html streams: testing the other path
would be testing code no student runs. Each run also reports time-to-first-token,
which is the number streaming exists to improve.

CREDENTIALS — this script never stores or prints your password. Set them in
the environment before running:

    PowerShell:  $env:CHATBOT_USER = "yourid"; $env:CHATBOT_PASS = "yourpassword"
    Bash:        export CHATBOT_USER=yourid CHATBOT_PASS=yourpassword

or put them in `.smoke-test-credentials.json` next to this script (gitignored):

    {"student_id": "yourid", "password": "yourpassword"}

COST — each tier gets one short prompt, so a full run is a fraction of a cent.
The estimated cost per tier is printed from model_config.json's own pricing,
and every run is logged to the dashboard under your student ID with the prompt
prefixed "[smoke-test]" so you can filter these out of real usage.

SCHOOL HOURS — outside Mon-Fri 7AM-5PM Pacific, api-proxy.php forces every
request to Haiku unless your account is `unlimited`. If that happens this
script tells you loudly, because otherwise you'd be testing Haiku eight times
and learning nothing about the other tiers.
"""

import argparse
import base64
import json
import os
import sys
import time
from pathlib import Path

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

HERE = Path(__file__).resolve().parent
PROXY_URL = "https://psd1.net/claude/api-proxy.php"
CONFIG_PATH = HERE / "model_config.json"
CREDS_PATH = HERE / ".smoke-test-credentials.json"

# psd1.net's ModSecurity rejects the default python-requests User-Agent with
# HTTP 406, so every request from a script must look like a browser.
HEADERS = {
    "Content-Type": "application/json",
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
}

# Short, unambiguous, and cheap. Asks for a fenced block with real indentation
# so the answer exercises the same rendering path index.html uses.
PROMPT = (
    "[smoke-test] Reply with nothing but a Python code block containing a "
    "function called greet(name) that prints a greeting using an if statement. "
    "Keep it under 10 lines."
)
IMAGE_PROMPT = "[smoke-test] In one short sentence, what color is this image?"

# 8x8 solid red PNG — smallest useful image for checking the vision path.
RED_PNG_B64 = (
    "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAAHElEQVQoU2P8z8Dwn4"
    "GKgHFUQ4bRMAoYRsMIAFsxA/9WLQpZAAAAAElFTkSuQmCC"
)

GREEN, RED, YELLOW, DIM, RESET = "\033[32m", "\033[31m", "\033[33m", "\033[2m", "\033[0m"
if os.name == "nt" and not os.environ.get("WT_SESSION"):
    # Old consoles render the escapes literally; plain text is better than noise.
    GREEN = RED = YELLOW = DIM = RESET = ""


def load_credentials():
    """Read credentials from the environment, falling back to a local file."""
    student_id = os.environ.get("CHATBOT_USER")
    password = os.environ.get("CHATBOT_PASS")
    if student_id and password:
        return student_id, password

    if CREDS_PATH.is_file():
        data = json.loads(CREDS_PATH.read_text(encoding="utf-8"))
        student_id = data.get("student_id")
        password = data.get("password")
        if student_id and password:
            return student_id, password

    sys.exit(
        "No credentials found.\n"
        "  Set CHATBOT_USER and CHATBOT_PASS in your environment, or create\n"
        f"  {CREDS_PATH.name} with {{\"student_id\": \"...\", \"password\": \"...\"}}\n"
        "  (that filename is gitignored)."
    )


def login(student_id, password):
    """Exchange credentials for a session token. Returns (token, is_unlimited)."""
    resp = requests.post(
        PROXY_URL,
        headers=HEADERS,
        json={"action": "verify_login", "student_id": student_id, "password": password},
        timeout=30,
    )
    if resp.status_code == 406:
        sys.exit("HTTP 406 — ModSecurity blocked the request. The browser User-Agent header is missing.")
    try:
        data = resp.json()
    except ValueError:
        sys.exit(f"Login returned non-JSON (HTTP {resp.status_code}):\n{resp.text[:400]}")
    if not data.get("success"):
        sys.exit(f"Login failed: {data.get('error', 'unknown error')}")
    return data["token"], bool(data.get("is_unlimited"))


def estimate_cost(config, tier, usage):
    """Mirror api-proxy.php's calculateCostUsd() so the run reports its own cost."""
    pricing = config.get("tiers", {}).get(tier, {}).get("pricing")
    if not pricing or not usage:
        return None
    return (usage.get("input_tokens", 0) / 1e6) * pricing.get("input_per_mtok", 0) + (
        usage.get("output_tokens", 0) / 1e6
    ) * pricing.get("output_per_mtok", 0)


def check_code_block(text):
    """Describe the fenced code block in `text`, the way index.html would see it."""
    if "```" not in text:
        return "no code fence", False
    fences = text.count("```")
    if fences % 2 == 1:
        return "UNCLOSED fence (answer cut off)", False
    body = text.split("```", 1)[1].split("```", 1)[0]
    body = "\n".join(body.split("\n")[1:])  # drop the language line
    indented = any(line.startswith(("    ", "\t")) for line in body.split("\n"))
    if not indented:
        return "code block, but NO indentation", False
    return "code block OK", True


def test_tier(tier, tier_cfg, token, student_id, config, args):
    """Send one prompt to one tier and return a result dict."""
    supports_vision = bool(tier_cfg.get("supportsVision"))
    use_image = args.with_image and supports_vision

    if use_image:
        content = [
            {"type": "image", "source": {"type": "base64", "media_type": "image/png", "data": RED_PNG_B64}},
            {"type": "text", "text": IMAGE_PROMPT},
        ]
    else:
        content = PROMPT

    body = {
        "model": tier,
        "max_tokens": 8192,
        "messages": [{"role": "user", "content": content}],
        "temperature": 0.0,
        "student_id": student_id,
        "session_token": token,
    }
    if not args.no_stream:
        body["stream"] = True

    started = time.time()
    try:
        resp = requests.post(PROXY_URL, headers=HEADERS, json=body,
                             timeout=300, stream=not args.no_stream)
    except requests.RequestException as exc:
        return {"tier": tier, "ok": False, "note": f"request failed: {exc}", "seconds": time.time() - started}

    # The proxy only opens an event stream once the first text arrives, so an
    # error raised before then still comes back as ordinary JSON.
    first_token = None
    ctype = resp.headers.get("Content-Type") or ""
    looks_like_sse = "text/event-stream" in ctype

    if not looks_like_sse and not args.no_stream:
        # Safety net matching index.html: a mangled Content-Type must not make a
        # working stream look like a failure. Judge by shape, and say so.
        # Strip leading stray output before parsing: it lands on the first line,
        # and would otherwise make the opening frame unrecognisable and silently
        # drop the start of the answer.
        peek = resp.text.lstrip()
        if peek.startswith(("event:", "data:")):
            data, first_token = read_event_stream(peek.splitlines(), started)
            elapsed = time.time() - started
            result = {
                "tier": tier, "http": resp.status_code, "seconds": elapsed,
                "streamed": True, "model": data.get("model"),
                "stop_reason": data.get("stop_reason"), "usage": data.get("usage"),
                "image": use_image, "usage_estimated": data.get("usage_estimated"),
                "ctype_wrong": ctype,
            }
            result["cost"] = estimate_cost(config, tier, data.get("usage"))
            return finish_result(result, data.get("text", ""), use_image)

    if looks_like_sse:
        data, first_token = read_event_stream(
            resp.iter_lines(chunk_size=1, decode_unicode=True), started)
        elapsed = time.time() - started
        result = {
            "tier": tier, "http": resp.status_code, "seconds": elapsed,
            "first_token": first_token, "streamed": True,
            "model": data.get("model"), "stop_reason": data.get("stop_reason"),
            "usage": data.get("usage"), "image": use_image,
            "usage_estimated": data.get("usage_estimated"),
        }
        result["cost"] = estimate_cost(config, tier, data.get("usage"))
        if data.get("error"):
            err = data["error"]
            result["ok"] = False
            result["note"] = f"{err.get('type', 'error')}: {err.get('message', err)}"
            return result
        return finish_result(result, data.get("text", ""), use_image)

    elapsed = time.time() - started

    try:
        data = resp.json()
    except ValueError:
        return {
            "tier": tier, "ok": False, "seconds": elapsed,
            "note": f"HTTP {resp.status_code}, non-JSON body: {resp.text[:200]}",
        }

    result = {
        "tier": tier,
        "http": resp.status_code,
        "seconds": elapsed,
        "model": data.get("model"),
        "stop_reason": data.get("stop_reason"),
        "usage": data.get("usage"),
        "notice": data.get("_notice"),
        "image": use_image,
    }
    result["cost"] = estimate_cost(config, tier, data.get("usage"))

    if "error" in data:
        err = data["error"]
        message = err.get("message", err) if isinstance(err, dict) else err
        result["ok"] = False
        result["error_type"] = err.get("type") if isinstance(err, dict) else None
        result["note"] = f"{result['error_type'] or 'error'}: {message}"
        return result

    text = "".join(
        block.get("text", "") for block in data.get("content", []) if block.get("type") == "text"
    )
    return finish_result(result, text, use_image)


def read_event_stream(lines, started):
    """Read the proxy's SSE reply, returning (data, seconds-to-first-token).

    Mirrors consumeAssistantStream() in index.html: `delta` events carry text,
    `done` carries the totals, `error` reports a failure after text began.
    """
    text = ""
    meta = {}
    failure = None
    first_token = None
    event = "message"

    for raw in lines:
        if raw is None:
            continue
        line = raw.rstrip("\r")
        if line == "":
            event = "message"
            continue
        if line.startswith(":"):
            continue
        if line.startswith("event:"):
            event = line[6:].strip()
            continue
        if not line.startswith("data:"):
            continue
        try:
            payload = json.loads(line[5:].strip())
        except ValueError:
            continue
        if event == "delta":
            if first_token is None:
                first_token = time.time() - started
            text += payload.get("text", "")
        elif event == "done":
            meta = payload
        elif event == "error":
            failure = payload

    out = dict(meta)
    out["text"] = text
    if failure:
        out["error"] = failure
    return out, first_token


def finish_result(result, text, use_image):
    """Judge one answer the same way regardless of how it was transported."""
    result["text"] = text
    result["chars"] = len(text)

    if not text.strip():
        result["ok"] = False
        result["note"] = "EMPTY answer (tokens billed, nothing returned)"
        return result

    if use_image:
        # Vision check: did it actually see the picture?
        saw_red = "red" in text.lower()
        result["ok"] = saw_red
        result["note"] = "described the image" if saw_red else f"did not identify the image: {text[:60]!r}"
        return result

    note, ok = check_code_block(text)
    result["ok"] = ok
    result["note"] = note
    if result.get("stop_reason") == "max_tokens":
        result["ok"] = False
        result["note"] += " + hit the token cap"
    return result


def main():
    parser = argparse.ArgumentParser(description="Smoke-test every chatbot model tier.")
    parser.add_argument("--tier", help="test only this tier (e.g. dspro)")
    parser.add_argument("--with-image", action="store_true", help="send a test image to vision-capable tiers")
    parser.add_argument("--verbose", action="store_true", help="print each answer in full")
    parser.add_argument("--no-stream", action="store_true",
                        help="use the non-streaming path instead of streaming")
    parser.add_argument("--url", default=None,
                        help="proxy URL to test (defaults to the live site)")
    args = parser.parse_args()

    if args.url:
        global PROXY_URL
        PROXY_URL = args.url

    config = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    tiers = config.get("tiers", {})
    if args.tier:
        if args.tier not in tiers:
            sys.exit(f"Unknown tier {args.tier!r}. Known tiers: {', '.join(tiers)}")
        tiers = {args.tier: tiers[args.tier]}

    student_id, password = load_credentials()
    token, is_unlimited = login(student_id, password)
    del password  # not needed again

    now = time.localtime()
    school_hours = now.tm_wday < 5 and 7 <= now.tm_hour < 17
    print(f"Proxy:   {PROXY_URL}")
    print(f"Account: {student_id} ({'unlimited' if is_unlimited else 'standard'})")
    if not school_hours and not is_unlimited:
        print(
            f"\n{YELLOW}WARNING: outside school hours on a standard account — api-proxy.php will\n"
            f"force every tier to Haiku, so these results say nothing about the other\n"
            f"models. Run this Mon-Fri 7AM-5PM Pacific, or from an unlimited account.{RESET}"
        )
    transport = "non-streaming" if args.no_stream else "streaming"
    print(f"Testing {len(tiers)} tier(s), {transport}...\n")

    results = []
    for tier, tier_cfg in tiers.items():
        print(f"  {tier:9s} ... ", end="", flush=True)
        result = test_tier(tier, tier_cfg, token, student_id, config, args)
        results.append(result)
        mark = f"{GREEN}PASS{RESET}" if result["ok"] else f"{RED}FAIL{RESET}"
        ttft = result.get("first_token")
        timing = f"{result['seconds']:.1f}s" + (f" (first token {ttft:.1f}s)" if ttft else "")
        print(f"{mark}  {timing}  {result['note']}")
        if result.get("ctype_wrong"):
            print(f"            {YELLOW}server sent Content-Type {result['ctype_wrong']!r} "
                  f"instead of text/event-stream — read as a stream anyway{RESET}")
        if result.get("usage_estimated"):
            print(f"            {YELLOW}token counts are ESTIMATED "
                  f"(provider sent no usage on the stream){RESET}")

        expected = tier_cfg.get("primary")
        got = result.get("model")
        if got and expected and expected not in got and got not in expected:
            print(f"            {DIM}answered as {got!r}, config says {expected!r}{RESET}")
        if result.get("notice"):
            print(f"            {YELLOW}server notice: {result['notice']}{RESET}")
        if args.verbose and result.get("text"):
            body = "\n".join("            " + line for line in result["text"].split("\n"))
            print(f"{DIM}{body}{RESET}")

    print("\n" + "=" * 72)
    passed = [r for r in results if r["ok"]]
    failed = [r for r in results if not r["ok"]]
    total_cost = sum(r["cost"] for r in results if r.get("cost"))
    total_out = sum((r.get("usage") or {}).get("output_tokens", 0) for r in results)
    print(f"Passed: {len(passed)}/{len(results)}   output tokens: {total_out:,}   est. cost: ${total_cost:.4f}")
    if failed:
        print(f"\n{RED}Tiers needing attention:{RESET}")
        for r in failed:
            print(f"  {r['tier']:9s} {r['note']}")
    print("=" * 72)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

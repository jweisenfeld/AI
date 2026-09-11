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

    python smoke-test.py                 # all tiers, text only
    python smoke-test.py --tier dspro    # just one tier
    python smoke-test.py --with-image    # also send a test image to vision tiers
    python smoke-test.py --verbose       # print each answer in full

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

    started = time.time()
    try:
        resp = requests.post(PROXY_URL, headers=HEADERS, json=body, timeout=180)
    except requests.RequestException as exc:
        return {"tier": tier, "ok": False, "note": f"request failed: {exc}", "seconds": time.time() - started}
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
    if result["stop_reason"] == "max_tokens":
        result["ok"] = False
        result["note"] += " + hit the token cap"
    return result


def main():
    parser = argparse.ArgumentParser(description="Smoke-test every chatbot model tier.")
    parser.add_argument("--tier", help="test only this tier (e.g. dspro)")
    parser.add_argument("--with-image", action="store_true", help="send a test image to vision-capable tiers")
    parser.add_argument("--verbose", action="store_true", help="print each answer in full")
    args = parser.parse_args()

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
    print(f"Testing {len(tiers)} tier(s)...\n")

    results = []
    for tier, tier_cfg in tiers.items():
        print(f"  {tier:9s} ... ", end="", flush=True)
        result = test_tier(tier, tier_cfg, token, student_id, config, args)
        results.append(result)
        mark = f"{GREEN}PASS{RESET}" if result["ok"] else f"{RED}FAIL{RESET}"
        print(f"{mark}  {result['seconds']:.1f}s  {result['note']}")

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

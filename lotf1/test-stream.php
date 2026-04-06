<?php
/**
 * lotf1/test-stream.php — Unit tests for the Lord of the Flies Text Navigator
 *
 * Tests every layer of the pipeline in order so the first failing test
 * immediately points to the root cause.
 *
 * Pipeline order:
 *   1. Pre-flight    — API key, PDF, Files API URI file on disk
 *   2. Files API     — stored URI is ACTIVE and readable by Gemini
 *   3. Stream / Quick Search (gemini-2.5-flash-lite)
 *   4. Stream / Full Search  (gemini-2.5-flash)
 *   5. Non-streaming fallback (both models)
 *   6. System-prompt enforcement — interpretation Qs declined server-side
 *   7. Payload size guard — oversized history rejected before hitting Gemini
 *   8. Explicit cache files — created in .secrets/ after a warm request
 *
 * Usage:
 *   Web: https://psd1.net/lotf1/test-stream.php?secret=amentum2025
 *   CLI: php test-stream.php
 */

set_time_limit(300);

// ── Access guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && ($_GET['secret'] ?? '') !== 'amentum2025') {
    http_response_code(403);
    exit('403 Forbidden — pass ?secret=amentum2025');
}

$IS_CLI = (php_sapi_name() === 'cli');

// ── Output helpers ────────────────────────────────────────────────────────────
function out(string $line): void {
    global $IS_CLI;
    if ($IS_CLI) {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . '<br>' . PHP_EOL;
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function section(string $title): void {
    out('');
    out(str_repeat('═', 60));
    out("  $title");
    out(str_repeat('═', 60));
}

$PASS = 0;
$FAIL = 0;

function pass(string $label): void {
    global $PASS;
    $PASS++;
    out("  [PASS] $label");
}

function fail(string $label, string $detail = ''): void {
    global $FAIL;
    $FAIL++;
    $msg = "  [FAIL] $label";
    if ($detail !== '') $msg .= "\n         → $detail";
    out($msg);
}

function info(string $label, string $value): void {
    out("  [INFO] $label: $value");
}

function skip(string $label): void {
    out("  [SKIP] $label");
}

// ── Shared config ─────────────────────────────────────────────────────────────
$PROXY_URL     = 'https://psd1.net/lotf1/api-proxy.php';
$ACCOUNT_ROOT  = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$SECRETS_FILE  = $ACCOUNT_ROOT . '/.secrets/amentum_geminikey.php';
$CACHE_URI_FILE = $ACCOUNT_ROOT . '/.secrets/lotf1_cache_name.txt';
$PDF_FILE      = __DIR__ . '/Lord-of-the-Flies.pdf';
$MODELS        = ['gemini-2.5-flash-lite', 'gemini-2.5-flash'];

// A simple locator question the bot must answer (not interpret)
$LOCATOR_Q = 'Where does Ralph blow the conch for the first time? Quote the passage.';

// An interpretation question the bot must decline
$INTERP_Q  = 'What does the conch symbolize?';

// ── curl helpers ──────────────────────────────────────────────────────────────

/**
 * POST JSON to $url, return ['code'=>int, 'body'=>string, 'ct'=>string, 'err'=>string].
 */
function post_json(string $url, string $payload, int $timeout = 120): array {
    $code = 0; $ct = '';
    $headerCb = function($ch, $h) use (&$code, &$ct): int {
        if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $h, $m)) $code = (int)$m[1];
        if (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13));
        return strlen($h);
    };
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        $timeout);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCb);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'ct' => $ct, 'err' => $err];
}

/**
 * Parse SSE body. Returns:
 *   accumulated  — all text deltas joined
 *   chunks       — count of data events with text
 *   done         — bool: [DONE] received
 *   meta         — the meta event payload or null
 *   error        — first error payload or null
 */
function parse_sse(string $raw): array {
    $accumulated = '';
    $chunks      = 0;
    $done        = false;
    $meta        = null;
    $error       = null;

    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $payload = substr($line, 6);

        if (trim($payload) === '[DONE]') { $done = true; continue; }

        $j = json_decode($payload, true);
        if ($j === null) continue;

        if (isset($j['error']) && $error === null) $error = $j['error'];
        if (isset($j['meta']))                     $meta  = $j['meta'];

        if (isset($j['text']) && $j['text'] !== '') {
            $accumulated .= $j['text'];
            $chunks++;
        }
    }

    return compact('accumulated', 'chunks', 'done', 'meta', 'error');
}

/**
 * Build the JSON payload for a chat request through api-proxy.php.
 */
function chat_payload(string $model, string $question): string {
    return json_encode([
        'session_id' => 'test_script',
        'model'      => $model,
        'messages'   => [
            ['role' => 'user', 'parts' => [['text' => $question]]],
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════
// TEST 1 — Pre-flight checks
// ═══════════════════════════════════════════════════════════════
section('TEST 1: Pre-flight checks');

// 1a. API key file
if (file_exists($SECRETS_FILE)) {
    pass("API key file exists ($SECRETS_FILE)");
    require_once $SECRETS_FILE;
    $API_KEY = trim($GEMINI_API_KEY ?? '');
    if (strlen($API_KEY) > 10) {
        pass('API key is non-empty (first 8 chars: ' . substr($API_KEY, 0, 8) . '…)');
    } else {
        fail('API key is non-empty', 'Key is blank or too short — check amentum_geminikey.php');
        $API_KEY = '';
    }
} else {
    fail("API key file exists", "Not found: $SECRETS_FILE");
    $API_KEY = '';
}

// 1b. Lord-of-the-Flies.pdf
if (file_exists($PDF_FILE)) {
    $pdfBytes = filesize($PDF_FILE);
    pass('Lord-of-the-Flies.pdf present (' . number_format($pdfBytes) . ' bytes)');
    if ($pdfBytes < 50_000) {
        fail('PDF size looks reasonable', "Only $pdfBytes bytes — file may be truncated or wrong file");
    } else {
        pass('PDF size looks reasonable (≥ 50 KB)');
    }
} else {
    fail('Lord-of-the-Flies.pdf present', "Not found at $PDF_FILE — place the PDF here and run cache-create.php");
}

// 1c. Files API URI file exists
if (file_exists($CACHE_URI_FILE)) {
    pass("lotf1_cache_name.txt exists ($CACHE_URI_FILE)");

    $storedUri = trim(file_get_contents($CACHE_URI_FILE));

    // 1d. URI looks like a Gemini Files API URI
    if (strpos($storedUri, 'generativelanguage.googleapis.com') !== false) {
        pass('Stored URI contains generativelanguage.googleapis.com');
    } else {
        fail('Stored URI looks like a Gemini Files API URI', "Got: $storedUri");
        $storedUri = '';
    }

    // 1e. Expiry file and freshness
    $expiryFile = $CACHE_URI_FILE . '.expires';
    if (file_exists($expiryFile)) {
        $expireTime = (int)trim(file_get_contents($expiryFile));
        $remaining  = $expireTime - time();
        if ($remaining > 0) {
            $hrs = round($remaining / 3600, 1);
            pass("Files API URI is not expired ($hrs h remaining)");
        } else {
            fail('Files API URI is not expired',
                'URI expired ' . abs(round($remaining / 3600, 1)) . ' h ago — run cache-ping.php or cache-create.php');
            $storedUri = '';
        }
        info('URI expires', date('Y-m-d H:i:s', $expireTime) . ' UTC');
    } else {
        fail('Expiry file exists', "Not found: $expiryFile — re-run cache-create.php");
        $storedUri = '';
    }

} else {
    fail('lotf1_cache_name.txt exists',
        "Not found: $CACHE_URI_FILE — run https://psd1.net/lotf1/cache-create.php?secret=amentum2025");
    $storedUri = '';
}

// ═══════════════════════════════════════════════════════════════
// TEST 2 — Files API: verify stored URI is ACTIVE
// ═══════════════════════════════════════════════════════════════
section('TEST 2: Files API — stored URI is ACTIVE');

if (empty($storedUri) || empty($API_KEY)) {
    skip('Skipping — no valid URI or API key from TEST 1');
} else {
    // Extract "files/xxxx" from the full URI to build a GET request
    $fileName = preg_replace('#^.*/v1beta/#', '', $storedUri);
    $getUrl   = "https://generativelanguage.googleapis.com/v1beta/{$fileName}?key={$API_KEY}";

    $ch = curl_init($getUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    $getBody = curl_exec($ch);
    $getCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $getErr  = curl_error($ch);
    curl_close($ch);

    if ($getErr) {
        fail('Files API GET request succeeded', "curl error: $getErr");
    } elseif ($getCode === 200) {
        pass('Files API GET returned HTTP 200');
        $fileData = json_decode($getBody, true);
        $state    = $fileData['state']      ?? 'UNKNOWN';
        $mime     = $fileData['mimeType']   ?? 'UNKNOWN';
        $sizeBytes = $fileData['sizeBytes'] ?? '?';

        info('File state',    $state);
        info('MIME type',     $mime);
        info('Size (bytes)',  (string)$sizeBytes);

        if ($state === 'ACTIVE') {
            pass("File state is ACTIVE");
        } else {
            fail("File state is ACTIVE", "Got: $state — file may still be processing or has been deleted");
        }

        if ($mime === 'application/pdf') {
            pass("MIME type is application/pdf");
        } else {
            fail("MIME type is application/pdf", "Got: $mime");
        }

    } else {
        fail("Files API GET returned HTTP 200", "Got: $getCode — URI may be expired or API key wrong");
        $detail = json_decode($getBody, true);
        info('Error message', $detail['error']['message'] ?? $getBody);
    }
}

// ═══════════════════════════════════════════════════════════════
// TEST 3 — Streaming SSE: Quick Search (gemini-2.5-flash-lite)
// ═══════════════════════════════════════════════════════════════
section('TEST 3: Streaming SSE — Quick Search (gemini-2.5-flash-lite)');

$payload3 = chat_payload('gemini-2.5-flash-lite', $LOCATOR_Q);
out("  Question: \"$LOCATOR_Q\"");
out('');

$r3 = post_json($PROXY_URL . '?stream=1', $payload3, 120);

if ($r3['err']) {
    fail('curl request to api-proxy.php?stream=1 succeeded', 'curl error: ' . $r3['err']);
} else {
    pass('curl request to api-proxy.php?stream=1 succeeded');
}

$sse3 = parse_sse($r3['body']);

info('HTTP status',   (string)$r3['code']);
info('Content-Type',  $r3['ct']);
info('Chunks (events with text)', (string)$sse3['chunks']);
info('[DONE] received', $sse3['done'] ? 'yes' : 'no');
info('Accumulated length', strlen($sse3['accumulated']) . ' chars');
info('First 200 chars', '"' . substr($sse3['accumulated'], 0, 200) . '"');
out('');

if ($r3['code'] === 200) {
    pass('HTTP 200 OK');
} else {
    fail('HTTP 200 OK', 'Got HTTP ' . $r3['code']);
}

if (stripos($r3['ct'], 'text/event-stream') !== false) {
    pass('Content-Type: text/event-stream');
} else {
    fail('Content-Type: text/event-stream', 'Got: ' . $r3['ct']);
}

if ($sse3['done']) {
    pass('[DONE] sentinel received');
} else {
    fail('[DONE] sentinel received', 'Stream ended without [DONE] — PHP may have crashed mid-response');
}

if ($sse3['error'] !== null) {
    $errStr = is_array($sse3['error']) ? json_encode($sse3['error']) : (string)$sse3['error'];
    fail('No error event in stream', "Got: $errStr");
} else {
    pass('No error event in stream');
}

if ($sse3['chunks'] > 0) {
    pass("Non-zero text chunk count ({$sse3['chunks']} data events)");
} else {
    fail('Non-zero text chunk count',
        'No text was emitted — check: is PDF uploaded? Is model name valid? Is Gemini returning candidates?');
}

if (strlen($sse3['accumulated']) > 20) {
    pass('Response text is non-empty (> 20 chars)');
} else {
    fail('Response text is non-empty (> 20 chars)',
        'Only ' . strlen($sse3['accumulated']) . ' chars received — likely a Gemini-side empty response');
}

if ($sse3['meta'] !== null) {
    pass('meta SSE event present');
    $cachedTok3 = (int)($sse3['meta']['cachedTokens'] ?? -1);
    info('cachedTokens', (string)$cachedTok3);
    if ($cachedTok3 > 0) {
        pass("Explicit cache HIT — $cachedTok3 tokens served from cache");
    } else {
        out('  [NOTE] cachedTokens=0 — cache miss or cold start. Run once more to warm cache. Not a failure.');
    }
} else {
    fail('meta SSE event present', 'No data: {"meta":{...}} line found — PHP may have exited before emitting meta');
}

// ═══════════════════════════════════════════════════════════════
// TEST 4 — Streaming SSE: Full Search (gemini-2.5-flash)
// ═══════════════════════════════════════════════════════════════
section('TEST 4: Streaming SSE — Full Search (gemini-2.5-flash)');

$payload4 = chat_payload('gemini-2.5-flash', $LOCATOR_Q);
out("  Question: \"$LOCATOR_Q\"");
out('');

$r4 = post_json($PROXY_URL . '?stream=1', $payload4, 120);
$sse4 = parse_sse($r4['body']);

info('HTTP status',       (string)$r4['code']);
info('Content-Type',      $r4['ct']);
info('Chunks',            (string)$sse4['chunks']);
info('[DONE] received',   $sse4['done'] ? 'yes' : 'no');
info('Accumulated length', strlen($sse4['accumulated']) . ' chars');
info('First 200 chars',   '"' . substr($sse4['accumulated'], 0, 200) . '"');
out('');

$r4['code'] === 200
    ? pass('HTTP 200 OK')
    : fail('HTTP 200 OK', 'Got HTTP ' . $r4['code']);

stripos($r4['ct'], 'text/event-stream') !== false
    ? pass('Content-Type: text/event-stream')
    : fail('Content-Type: text/event-stream', 'Got: ' . $r4['ct']);

$sse4['done']
    ? pass('[DONE] sentinel received')
    : fail('[DONE] sentinel received');

$sse4['error'] === null
    ? pass('No error event in stream')
    : fail('No error event in stream', json_encode($sse4['error']));

$sse4['chunks'] > 0
    ? pass("Non-zero text chunk count ({$sse4['chunks']} data events)")
    : fail('Non-zero text chunk count', 'No text emitted');

strlen($sse4['accumulated']) > 20
    ? pass('Response text is non-empty (> 20 chars)')
    : fail('Response text is non-empty', strlen($sse4['accumulated']) . ' chars received');

if ($sse4['meta'] !== null) {
    pass('meta SSE event present');
    $cachedTok4 = (int)($sse4['meta']['cachedTokens'] ?? -1);
    info('cachedTokens', (string)$cachedTok4);
    $cachedTok4 > 0
        ? pass("Explicit cache HIT — $cachedTok4 tokens served from cache")
        : out('  [NOTE] cachedTokens=0 — cache miss or cold start. Not a failure.');
} else {
    fail('meta SSE event present', 'No data: {"meta":{...}} line found');
}

// ═══════════════════════════════════════════════════════════════
// TEST 5 — Non-streaming fallback route
// ═══════════════════════════════════════════════════════════════
section('TEST 5: Non-streaming fallback route');

foreach ($MODELS as $m5) {
    out("  Model: $m5");
    $payload5 = chat_payload($m5, $LOCATOR_Q);
    $r5 = post_json($PROXY_URL, $payload5, 120);

    info('HTTP status',  (string)$r5['code']);
    info('Content-Type', $r5['ct']);

    $r5['code'] === 200
        ? pass("[$m5] HTTP 200 OK")
        : fail("[$m5] HTTP 200 OK", 'Got HTTP ' . $r5['code']);

    stripos($r5['ct'], 'application/json') !== false
        ? pass("[$m5] Content-Type: application/json")
        : fail("[$m5] Content-Type: application/json", 'Got: ' . $r5['ct']);

    $parsed5 = json_decode($r5['body'], true);

    $parsed5 !== null
        ? pass("[$m5] Response body is valid JSON")
        : fail("[$m5] Response body is valid JSON", 'Body: ' . substr($r5['body'], 0, 120));

    // Extract text the same way the JS fallback does: candidates[0].content.parts[0].text
    $text5 = $parsed5['candidates'][0]['content']['parts'][0]['text'] ?? null;

    ($text5 !== null && strlen($text5) > 10)
        ? pass("[$m5] Response text extracted (" . strlen($text5) . " chars)")
        : fail("[$m5] Response text extracted",
            'Not found at candidates[0].content.parts[0].text — error: ' .
            ($parsed5['error']['message'] ?? json_encode($parsed5['error'] ?? 'no error key')));
    out('');
}

// ═══════════════════════════════════════════════════════════════
// TEST 6 — System-prompt enforcement (server-side)
// ═══════════════════════════════════════════════════════════════
section('TEST 6: System-prompt enforcement — interpretation Qs declined');

out("  Question: \"$INTERP_Q\"");
out('  (Bot should decline, not explain symbolism)');
out('');

$payload6 = chat_payload('gemini-2.5-flash-lite', $INTERP_Q);
$r6 = post_json($PROXY_URL . '?stream=1', $payload6, 60);
$sse6 = parse_sse($r6['body']);

info('HTTP status', (string)$r6['code']);
info('Accumulated', '"' . substr($sse6['accumulated'], 0, 300) . '"');
out('');

// The bot should produce SOME response (not empty)
strlen($sse6['accumulated']) > 10
    ? pass('Bot produced a response to interpretation question (not silent)')
    : fail('Bot produced a response to interpretation question', 'Got empty response — check streaming pipeline first');

// The response must NOT contain deep interpretation language
// (it should redirect, not explain)
$text6   = strtolower($sse6['accumulated']);
$badWords = ['represents', 'symbolizes', 'symbolises', 'theme of', 'thematically',
             'stands for', 'is a metaphor', 'allegor'];
$found6  = [];
foreach ($badWords as $w) {
    if (strpos($text6, $w) !== false) $found6[] = $w;
}

empty($found6)
    ? pass('Response does not contain interpretation language (' . implode(', ', $badWords) . ')')
    : fail('Response does not contain interpretation language',
        'Found: ' . implode(', ', $found6) . ' — system prompt may not be loading correctly');

// The response should include a redirect phrase
$redirectPhrases = ['interpretation', 'find passages', 'where ', 'asking where', 'try asking'];
$hasRedirect = false;
foreach ($redirectPhrases as $p) {
    if (strpos($text6, $p) !== false) { $hasRedirect = true; break; }
}
$hasRedirect
    ? pass('Response contains a redirect/decline phrase')
    : out('  [NOTE] Response does not contain an obvious redirect phrase — review content above');

// ═══════════════════════════════════════════════════════════════
// TEST 7 — Payload size guard (oversized history rejected)
// ═══════════════════════════════════════════════════════════════
section('TEST 7: Payload size guard — oversized history rejected');

// Build history that exceeds lotf1's 40k char limit
$bigMessages = [];
for ($i = 0; $i < 220; $i++) {
    $role = ($i % 2 === 0) ? 'user' : 'model';
    $bigMessages[] = [
        'role'  => $role,
        'parts' => [['text' => "Synthetic filler message $i to bloat the history beyond the 40,000-character server-side guard. " .
                               "It needs enough text per entry to accumulate quickly, so here is more padding text that does nothing useful."]],
    ];
}

$oversizedPayload = json_encode([
    'session_id' => 'test_oversize',
    'model'      => 'gemini-2.5-flash-lite',
    'messages'   => $bigMessages,
]);
info('Oversized payload size', number_format(strlen($oversizedPayload)) . ' chars (limit: 40,000)');
out('');

// 7a — streaming route
out('  Sub-test 7a: streaming route (?stream=1)');
$r7a  = post_json($PROXY_URL . '?stream=1', $oversizedPayload, 30);
$sse7a = parse_sse($r7a['body']);

$sse7a['error'] !== null && $sse7a['chunks'] === 0
    ? pass('Streaming route returned error event with no text (correctly rejected)')
    : fail('Streaming route correctly rejected oversized payload',
        'error=' . json_encode($sse7a['error']) . ' chunks=' . $sse7a['chunks']);

if ($sse7a['error'] !== null) {
    $errStr = is_array($sse7a['error']) ? json_encode($sse7a['error']) : $sse7a['error'];
    info('Error message', $errStr);
}

// 7b — non-streaming fallback
out('');
out('  Sub-test 7b: non-streaming fallback');
$r7b = post_json($PROXY_URL, $oversizedPayload, 30);
$parsed7b = json_decode($r7b['body'], true);
$err7b    = $parsed7b['error'] ?? null;

($err7b !== null && stripos((string)$err7b, 'too large') !== false)
    ? pass('Non-streaming route returned {"error":"...too large..."} (correctly rejected)')
    : fail('Non-streaming route correctly rejected oversized payload',
        'Body: ' . substr($r7b['body'], 0, 150));

// ═══════════════════════════════════════════════════════════════
// TEST 8 — Explicit cache files exist in .secrets/
// ═══════════════════════════════════════════════════════════════
section('TEST 8: Explicit cache files exist in .secrets/ after warm request');

// These files are created by get_or_create_explicit_cache() in api-proxy.php.
// They should exist if Tests 3/4 succeeded and fileUri was valid.
$cacheFileChecks = [
    'gemini-2.5-flash-lite' => 'gemini_explicit_cache_lotf1_gemini-2-5-flash-lite.txt',
    'gemini-2.5-flash'      => 'gemini_explicit_cache_lotf1_gemini-2-5-flash.txt',
];

if (empty($storedUri)) {
    skip('Skipping — no valid Files API URI from TEST 1; explicit cache can\'t be created');
} else {
    foreach ($cacheFileChecks as $model => $filename) {
        $filepath = $ACCOUNT_ROOT . '/.secrets/' . $filename;
        if (file_exists($filepath)) {
            $cacheName   = trim(file_get_contents($filepath));
            $expiryPath  = $filepath . '.expires';
            $expireTime  = file_exists($expiryPath)
                ? (int)trim(file_get_contents($expiryPath)) : 0;
            $remaining   = $expireTime - time();

            pass("[$model] $filename exists");
            info("  cache name",  $cacheName);
            info("  expires",     $expireTime > 0 ? date('Y-m-d H:i:s', $expireTime) . ' (' . round($remaining/3600, 1) . ' h)' : 'unknown');

            $remaining > 0
                ? pass("[$model] Explicit cache is not expired")
                : fail("[$model] Explicit cache is not expired",
                    "Expired " . abs(round($remaining/3600, 1)) . " h ago — will be recreated on next request");
        } else {
            // This is expected if Tests 3/4 got empty responses (no cache needed) or PDF wasn't uploaded
            fail("[$model] $filename exists",
                "Not found: $filepath — run a successful query first, or check that fileUri is valid");
        }
    }

    // Check for lock files that are stuck — meaning old AND the cache is missing/expired.
    // A lock file that is old but the cache is healthy is just harmless litter:
    // fopen/flock creates the file but never deletes it after successful creation.
    out('');
    out('  Checking for stuck lock files (old lock + missing/expired cache = problem)…');
    $lockStuck = false;
    foreach ($cacheFileChecks as $model => $filename) {
        $lockPath  = $ACCOUNT_ROOT . '/.secrets/' . $filename . '.lock';
        $cachePath = $ACCOUNT_ROOT . '/.secrets/' . $filename;
        $expiryPath = $cachePath . '.expires';
        if (file_exists($lockPath)) {
            $lockAge = time() - filemtime($lockPath);
            // Cache is healthy if the cache file exists and is not expired
            $cacheHealthy = false;
            if (file_exists($cachePath) && file_exists($expiryPath)) {
                $cacheHealthy = (time() < (int)trim(file_get_contents($expiryPath)));
            }
            if ($lockAge > 300 && !$cacheHealthy) {
                // Old lock + broken cache = something is actually stuck
                fail("[$model] Lock file is stuck",
                    "$lockPath is $lockAge seconds old and the cache is missing/expired — delete the lock file");
                $lockStuck = true;
            } else {
                // Old lock + healthy cache = normal leftover artifact, not a problem
                info("[$model] Lock file", $lockAge . "s old, cache " . ($cacheHealthy ? "healthy ✓" : "missing (will be recreated on next request)"));
            }
        }
    }
    if (!$lockStuck) pass('No stuck lock files (old lock + broken cache)');
}

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════
section('Summary');
out("  Tests passed : $PASS");
out("  Tests failed : $FAIL");
out('');
if ($FAIL === 0) {
    out('  All checks passed! The pipeline is healthy.');
} else {
    out('  Fix the FAIL items above in order from top to bottom —');
    out('  each test depends on the ones before it.');
}
out('');

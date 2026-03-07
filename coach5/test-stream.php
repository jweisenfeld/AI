<?php
/**
 * test-stream.php - Unit test for SSE streaming behavior of api-proxy.php
 *
 * Simulates what the browser ReadableStream parser does when consuming
 * Server-Sent Events from the proxy.
 *
 * Usage:
 *   CLI:  php test-stream.php
 *   Web:  https://psd1.net/coach4/test-stream.php?secret=amentum2025
 */

if (php_sapi_name() !== 'cli' && ($_GET['secret'] ?? '') !== 'amentum2025') {
    http_response_code(403);
    exit('Forbidden');
}

// ============================================================
// Output helpers
// ============================================================

$_isCli = (php_sapi_name() === 'cli');

function out(string $line): void {
    global $_isCli;
    if ($_isCli) {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . '<br>' . PHP_EOL;
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function section(string $title): void {
    out('');
    out(str_repeat('-', 60));
    out("  $title");
    out(str_repeat('-', 60));
}

function result_pass(string $label): void {
    out("  [PASS] $label");
}

function result_fail(string $label, string $detail = ''): void {
    $msg = "  [FAIL] $label";
    if ($detail !== '') {
        $msg .= ' -- ' . $detail;
    }
    out($msg);
}

// ============================================================
// Shared request payload
// ============================================================

$TEST_PAYLOAD = json_encode([
    'model'        => 'gemini-2.5-flash-lite',
    'student_id'   => 'test_script',
    'student_name' => 'Test Script',
    'system'       => 'You are a helpful assistant. Follow instructions exactly.',
    'messages'     => [
        [
            'role'  => 'user',
            'parts' => [['text' => 'Reply with exactly three words: testing works correctly']],
        ],
    ],
]);

$BASE_URL = 'https://psd1.net/coach4/api-proxy.php';

// ============================================================
// Helper: extract a text delta from a single SSE line.
//
// Handles both:
//   Format A (our wrapper)  : {"text":"..."}
//   Format B (Gemini raw)   : {"candidates":[{"content":{"parts":[{"text":"..."}]}}]}
//
// Returns the text string, or null if this line carries no text.
// ============================================================

function extract_text_from_sse_line(string $line): ?string
{
    // All SSE data lines start with "data: "
    if (strncmp($line, 'data: ', 6) !== 0) {
        return null;
    }

    $jsonStr = substr($line, 6);

    // [DONE] sentinel -- not JSON, handled by caller
    if (trim($jsonStr) === '[DONE]') {
        return null;
    }

    $parsed = json_decode($jsonStr, true);
    if ($parsed === null) {
        return null;
    }

    // Format A: simplified wrapper {"text":"..."}
    if (isset($parsed['text']) && is_string($parsed['text'])) {
        return $parsed['text'];
    }

    // Format B: Gemini raw SSE candidates array
    $textB = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($textB !== null && is_string($textB)) {
        return $textB;
    }

    return null;
}

// ============================================================
// Helper: detect the SSE [DONE] sentinel line
// ============================================================

function is_done_line(string $line): bool
{
    return trim($line) === 'data: [DONE]';
}

// ============================================================
// TEST 1 - Streaming route (?stream=1)
// ============================================================

section('TEST 1: Streaming SSE route (?stream=1)');

$streamUrl = $BASE_URL . '?stream=1';
out("  POST $streamUrl");

$rawBody           = '';
$chunkCount        = 0;
$doneReceived      = false;
$accumulated       = '';
$streamHttpCode    = 0;
$streamContentType = '';

$writeCallback = function ($ch, $data) use (&$rawBody): int {
    $rawBody .= $data;
    return strlen($data);
};

$headerCallback = function ($ch, $header) use (&$streamHttpCode, &$streamContentType): int {
    if (preg_match('/^HTTP\/\d[\.\d]* (\d+)/', $header, $m)) {
        $streamHttpCode = (int) $m[1];
    }
    if (stripos($header, 'Content-Type:') === 0) {
        $streamContentType = trim(substr($header, 13));
    }
    return strlen($header);
};

$ch = curl_init($streamUrl);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $TEST_PAYLOAD);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_WRITEFUNCTION,  $writeCallback);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // must be false with WRITEFUNCTION
curl_setopt($ch, CURLOPT_TIMEOUT,        120);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);

curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    result_fail('curl request completed', "curl error: $curlError");
} else {
    result_pass('curl request completed');
}

// Parse the full body exactly as a browser ReadableStream TextDecoder would:
// split on line boundaries then process line-by-line.
$lines = preg_split('/?
/', $rawBody);

foreach ($lines as $line) {
    if (is_done_line($line)) {
        $doneReceived = true;
        continue;
    }

    $delta = extract_text_from_sse_line($line);
    if ($delta !== null) {
        $chunkCount++;
        $accumulated .= $delta;
    }
}

out('');
out("  HTTP status        : $streamHttpCode");
out("  Content-Type       : $streamContentType");
out("  Chunks (data evts) : $chunkCount");
out("  [DONE] received    : " . ($doneReceived ? 'yes' : 'no'));
out("  Total text length  : " . strlen($accumulated) . ' chars');
out("  Accumulated text   : \"$accumulated\"");
out('');

if ($streamHttpCode === 200) {
    result_pass('HTTP 200 OK');
} else {
    result_fail('HTTP 200 OK', "got $streamHttpCode");
}

if (stripos($streamContentType, 'text/event-stream') !== false) {
    result_pass('Content-Type is text/event-stream');
} else {
    result_fail('Content-Type is text/event-stream', "got: $streamContentType");
}

if ($doneReceived) {
    result_pass('[DONE] sentinel received');
} else {
    result_fail('[DONE] sentinel received');
}

if ($chunkCount > 0) {
    result_pass("Non-zero chunk count ($chunkCount data events parsed)");
} else {
    result_fail('Non-zero chunk count', 'no text delta chunks were parsed');
}

if (strlen($accumulated) > 0) {
    result_pass('Response text is non-empty');
} else {
    result_fail('Response text is non-empty');
}

// ============================================================
// TEST 2 - Non-streaming fallback route (no ?stream param)
// ============================================================

section('TEST 2: Non-streaming fallback route (no ?stream)');

out("  POST $BASE_URL");

$fallbackHttpCode    = 0;
$fallbackContentType = '';

$fallbackHeaderCb = function ($ch, $header) use (&$fallbackHttpCode, &$fallbackContentType): int {
    if (preg_match('/^HTTP\/\d[\.\d]* (\d+)/', $header, $m)) {
        $fallbackHttpCode = (int) $m[1];
    }
    if (stripos($header, 'Content-Type:') === 0) {
        $fallbackContentType = trim(substr($header, 13));
    }
    return strlen($header);
};

$ch = curl_init($BASE_URL);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $TEST_PAYLOAD);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        120);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, $fallbackHeaderCb);

$fallbackBody  = curl_exec($ch);
$fallbackError = curl_error($ch);
curl_close($ch);

if ($fallbackError) {
    result_fail('curl request completed', "curl error: $fallbackError");
} else {
    result_pass('curl request completed');
}

$fallbackParsed = json_decode($fallbackBody, true);

// Standard Gemini generateContent response shape
$fallbackText = $fallbackParsed['candidates'][0]['content']['parts'][0]['text'] ?? null;

out('');
out("  HTTP status        : $fallbackHttpCode");
out("  Content-Type       : $fallbackContentType");
out("  JSON parse success : " . ($fallbackParsed !== null ? 'yes' : 'no'));
out("  Response text      : \"$fallbackText\"");
out('');

if ($fallbackHttpCode === 200) {
    result_pass('HTTP 200 OK');
} else {
    result_fail('HTTP 200 OK', "got $fallbackHttpCode");
}

if (stripos($fallbackContentType, 'application/json') !== false) {
    result_pass('Content-Type is application/json');
} else {
    result_fail('Content-Type is application/json', "got: $fallbackContentType");
}

if ($fallbackParsed !== null) {
    result_pass('Response body is valid JSON');
} else {
    result_fail('Response body is valid JSON');
}

if ($fallbackText !== null && strlen($fallbackText) > 0) {
    result_pass('Response text extracted and non-empty');
} else {
    result_fail(
        'Response text extracted and non-empty',
        'could not find text at candidates[0].content.parts[0].text'
    );
}

// ============================================================
// TEST 3 - Implicit caching verification
//
// Sends two identical requests (same system + file_data prefix).
// The second response should report cachedContentTokenCount > 0,
// confirming Google's implicit prefix cache kicked in.
//
// NOTE: The file URI must be active (run cache-create.php first).
//       If no file URI is stored, this test is skipped.
// ============================================================

section('TEST 3: Implicit caching (cachedContentTokenCount on 2nd request)');

$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';

if (!file_exists($cacheNameFile) || !file_exists($secretsFile)) {
    out('  SKIP: cache file URI or API key not found — run cache-create.php first.');
} else {
    $fileUri = trim(file_get_contents($cacheNameFile));
    if (empty($fileUri) || strpos($fileUri, 'generativelanguage.googleapis.com') === false) {
        out('  SKIP: gemini_cache_name.txt does not contain a valid Files API URI.');
    } else {
        require_once $secretsFile; // defines $GEMINI_API_KEY
        $apiKey = trim($GEMINI_API_KEY);

        // Use non-streaming route so we get a clean JSON response with usageMetadata
        $cacheTestUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

        // Stable system prompt (same on both calls — must never vary for caching)
        $stableSystem = 'You are a civil engineering mentor for 9th-grade students in Pasco, WA.';

        // Payload factory: file_data as first user turn, then a fixed question
        $makePayload = function() use ($fileUri, $stableSystem): string {
            return json_encode([
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['file_data' => ['mime_type' => 'text/plain', 'file_uri' => $fileUri]]],
                    ],
                    [
                        'role'  => 'user',
                        'parts' => [['text' => 'In one sentence, what is the Pasco Municipal Code?']],
                    ],
                ],
                'systemInstruction' => ['parts' => [['text' => $stableSystem]]],
            ]);
        };

        // Helper: POST payload, return decoded JSON
        $doRequest = function(string $payload) use ($cacheTestUrl): ?array {
            $ch = curl_init($cacheTestUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT,        120);
            $body = curl_exec($ch);
            curl_close($ch);
            return json_decode($body, true);
        };

        out('  Request 1 (warm-up — populates the cache)…');
        $r1 = $doRequest($makePayload());
        $cached1 = $r1['usageMetadata']['cachedContentTokenCount'] ?? 0;
        $prompt1 = $r1['usageMetadata']['promptTokenCount']        ?? 0;
        out("    promptTokenCount       : $prompt1");
        out("    cachedContentTokenCount: $cached1");

        out('');
        out('  Request 2 (should hit the implicit cache)…');
        $r2 = $doRequest($makePayload());
        $cached2 = $r2['usageMetadata']['cachedContentTokenCount'] ?? 0;
        $prompt2 = $r2['usageMetadata']['promptTokenCount']        ?? 0;
        out("    promptTokenCount       : $prompt2");
        out("    cachedContentTokenCount: $cached2");
        out('');

        if ($cached2 > 0) {
            result_pass("Cache HIT on request 2 — $cached2 tokens served from cache");
        } else {
            result_fail(
                'Cache HIT on request 2',
                'cachedContentTokenCount=0. Cache may need more warm-up or file URI may be stale.'
            );
        }

        // Sanity: cached tokens should be a large share of prompt tokens
        if ($cached2 > 0 && $prompt2 > 0) {
            $pct = round(100 * $cached2 / $prompt2);
            out("  Cache efficiency: $pct% of prompt tokens were cached");
        }
    }
}

// ============================================================
// TEST 4 - Payload size guard (MAX_HISTORY_CHARS enforcement)
//
// Sends an intentionally oversized messages payload (>40k chars)
// to both the streaming and non-streaming routes and verifies
// that api-proxy.php rejects it before touching the Gemini API.
//
// This confirms the server-side paste-back guard is working,
// independent of the client-side check in index.html.
// ============================================================

section('TEST 4: Payload size guard (oversized history rejected by server)');

// Build a message array whose JSON serialisation exceeds 40k chars.
// Each message is ~200 chars; 210 messages ≈ 42k chars.
$bigMessages = [];
for ($i = 0; $i < 210; $i++) {
    $role = ($i % 2 === 0) ? 'user' : 'model';
    $bigMessages[] = [
        'role'  => $role,
        'parts' => [['text' => "This is synthetic filler message number $i to simulate a student pasting back a very long conversation history into the chat window after a page refresh. It contains enough text to push past the 40k character limit when combined with many other messages like it."]],
    ];
}

$oversizedPayload = json_encode([
    'model'        => 'gemini-2.5-flash-lite',
    'student_id'   => 'test_script',
    'student_name' => 'Test Script',
    'system'       => 'You are a helpful assistant.',
    'messages'     => $bigMessages,
]);

out('  Oversized payload size: ' . number_format(strlen($oversizedPayload)) . ' chars (limit: 40,000)');
out('');

// ── Sub-test 4a: streaming route ──────────────────────────────────────────────
out('  Sub-test 4a: streaming route (?stream=1)');

$t4StreamBody = '';
$t4StreamCode = 0;
$t4StreamTyp  = '';

$t4HeaderCb = function($ch, $hdr) use (&$t4StreamCode, &$t4StreamTyp): int {
    if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $hdr, $m)) $t4StreamCode = (int)$m[1];
    if (stripos($hdr, 'Content-Type:') === 0) $t4StreamTyp = trim(substr($hdr, 13));
    return strlen($hdr);
};

$ch = curl_init($BASE_URL . '?stream=1');
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $oversizedPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, $t4HeaderCb);
$t4StreamBody = curl_exec($ch);
curl_close($ch);

// Expect an SSE error event (no text delta, contains 'error' key)
$t4StreamLines = preg_split('/\r?\n/', $t4StreamBody);
$t4ErrorFound  = false;
$t4TextFound   = false;
foreach ($t4StreamLines as $ln) {
    if (strncmp($ln, 'data: ', 6) !== 0) continue;
    $j = json_decode(substr($ln, 6), true);
    if (isset($j['error']))  $t4ErrorFound = true;
    if (isset($j['text']))   $t4TextFound  = true;
}

if ($t4ErrorFound && !$t4TextFound) {
    result_pass('Streaming route returned SSE error event (no text delta sent)');
} else {
    result_fail(
        'Streaming route returned SSE error event',
        'error_found=' . ($t4ErrorFound?'yes':'no') . ' text_found=' . ($t4TextFound?'yes':'no')
    );
}

// ── Sub-test 4b: non-streaming fallback route ─────────────────────────────────
out('');
out('  Sub-test 4b: non-streaming fallback route');

$ch = curl_init($BASE_URL);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $oversizedPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$t4FallbackBody   = curl_exec($ch);
curl_close($ch);

$t4FallbackParsed = json_decode($t4FallbackBody, true);
$t4FallbackError  = $t4FallbackParsed['error'] ?? null;

if ($t4FallbackError !== null && stripos($t4FallbackError, 'too large') !== false) {
    result_pass("Non-streaming route returned error JSON: \"$t4FallbackError\"");
} else {
    result_fail(
        'Non-streaming route returned error JSON with "too large" message',
        'got: ' . substr($t4FallbackBody, 0, 120)
    );
}

// ============================================================
// TEST 5 - Meta SSE event (cache badge data) present in stream
//
// Verifies that the streaming route emits a data: {"meta":{...}}
// event containing cachedTokens before [DONE].
// Uses a real request through api-proxy.php (requires file URI).
// cachedTokens may be 0 on first run — that's fine; we just need
// the meta event to be present and well-formed.
// ============================================================

section('TEST 5: meta SSE event present in streaming response');

$accountRoot5  = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$cacheFile5    = $accountRoot5 . '/.secrets/gemini_cache_name.txt';

if (!file_exists($cacheFile5)) {
    out('  SKIP: gemini_cache_name.txt not found — run cache-create.php first.');
} else {
    $t5RawBody  = '';
    $t5HttpCode = 0;

    $t5HeaderCb = function($ch, $hdr) use (&$t5HttpCode): int {
        if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $hdr, $m)) $t5HttpCode = (int)$m[1];
        return strlen($hdr);
    };

    $ch = curl_init($BASE_URL . '?stream=1');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $TEST_PAYLOAD);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, $t5HeaderCb);
    $t5RawBody = curl_exec($ch);
    curl_close($ch);

    // Parse every SSE line
    $t5Lines      = preg_split('/\r?\n/', $t5RawBody);
    $t5MetaFound  = false;
    $t5MetaValid  = false;
    $t5DoneFound  = false;
    $t5MetaBefore = false; // meta must arrive before [DONE]
    $t5CachedTok  = null;

    foreach ($t5Lines as $ln) {
        if (strncmp($ln, 'data: ', 6) !== 0) continue;
        $payload5 = substr($ln, 6);

        if (trim($payload5) === '[DONE]') {
            $t5DoneFound  = true;
            continue;
        }

        $j5 = json_decode($payload5, true);
        if ($j5 === null) continue;

        if (isset($j5['meta'])) {
            $t5MetaFound  = true;
            $t5MetaBefore = !$t5DoneFound; // true if meta arrived before [DONE]
            $t5CachedTok  = $j5['meta']['cachedTokens'] ?? 'MISSING';
            // Valid if cachedTokens key exists and is an integer
            $t5MetaValid  = array_key_exists('cachedTokens', $j5['meta'])
                         && is_int($j5['meta']['cachedTokens']);
        }
    }

    out("  meta event found   : " . ($t5MetaFound  ? 'yes' : 'no'));
    out("  meta before [DONE] : " . ($t5MetaBefore ? 'yes' : 'no'));
    out("  cachedTokens value : " . ($t5CachedTok !== null ? $t5CachedTok : '(not found)'));
    out('');

    if ($t5MetaFound) {
        result_pass('meta SSE event present in stream');
    } else {
        result_fail('meta SSE event present in stream', 'no data: {"meta":{...}} line found');
    }

    if ($t5MetaValid) {
        result_pass('meta.cachedTokens is a well-formed integer');
    } else {
        result_fail('meta.cachedTokens is a well-formed integer',
            'key missing or wrong type: ' . json_encode($t5CachedTok));
    }

    if ($t5MetaBefore) {
        result_pass('meta event arrives before [DONE] sentinel');
    } else {
        result_fail('meta event arrives before [DONE] sentinel',
            $t5MetaFound ? 'meta arrived AFTER [DONE]' : 'meta never arrived');
    }

    if ((int)$t5CachedTok > 0) {
        result_pass("Cache HIT confirmed in stream — {$t5CachedTok} cached tokens");
    } else {
        out('  NOTE: cachedTokens=0 (cache miss or cold start — not a failure)');
    }
}

// ============================================================
// Done
// ============================================================

section('Done');
out('All tests completed.');

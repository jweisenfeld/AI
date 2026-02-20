<?php
/**
 * test-stream.php - Unit test for SSE streaming behavior of api-proxy.php
 *
 * Simulates what the browser ReadableStream parser does when consuming
 * Server-Sent Events from the proxy.
 *
 * Usage:
 *   CLI:  php test-stream.php
 *   Web:  https://psd1.net/gemini3/test-stream.php?secret=amentum2025
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

$BASE_URL = 'https://psd1.net/gemini3/api-proxy.php';

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
// Done
// ============================================================

section('Done');
out('All tests completed.');

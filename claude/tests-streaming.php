<?php
/**
 * Streaming integration tests — exercises the REAL streaming functions in
 * api-proxy.php against a local fake model server, so no API keys are needed
 * and no request costs money.
 *
 * Run (background the server first):
 *     python tests-streaming-server.py
 *     php tests-streaming.php
 *
 * The CLI php on this machine ships without curl/mbstring enabled. If you see
 * "undefined function curl_init", point php at its own ext directory:
 *     php -d extension_dir="<php>/ext" -d extension=php_curl.dll -d extension=php_mbstring.dll tests-streaming.php
 *
 * Functions are lifted out of api-proxy.php rather than copied — a copy would
 * drift and prove nothing about the code that ships.
 */
define('API_TIMEOUT_SECONDS', 30);

$source = file_get_contents(__DIR__ . '/api-proxy.php');

function importFn(string $source, string $name): void
{
    if (function_exists($name)) return;
    $pattern = '/^function\s+' . preg_quote($name, '/') . '\s*\(.*?^\}/ms';
    if (!preg_match($pattern, $source, $m)) {
        throw new RuntimeException("could not extract {$name}()");
    }
    eval($m[0]);
}

foreach ([
    'makeLineSplitter',
    'normalizeOpenAiCompatibleResponse',
    'callAnthropicApiStreaming',
    'callOpenAiCompatibleApiStreaming',
    'estimateUsage',
] as $fn) {
    importFn($source, $fn);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; echo "FAIL  $label" . ($detail ? "  ($detail)" : '') . "\n"; }
}

$BASE = 'http://127.0.0.1:8899';
$EXPECTED = "Here is code:\n```python\ndef greet(n):\n    print(n)\n```";

// ---------- line splitter ----------
$lines = [];
$tail = '';
$splitter = makeLineSplitter(function ($l) use (&$lines) { $lines[] = $l; }, $tail);
$splitter(null, "alpha\nbe");
$splitter(null, "ta\ngamma\n");
check('line splitter reassembles a line cut across chunks', $lines === ['alpha', 'beta', 'gamma'],
      json_encode($lines));

$lines = [];
$tail2 = '';
$splitter2 = makeLineSplitter(function ($l) use (&$lines) { $lines[] = $l; }, $tail2);
$splitter2(null, "a\r\nb\r\n");
check('line splitter strips CR from CRLF', $lines === ['a', 'b'], json_encode($lines));

// A final line with no trailing newline must survive: that is exactly how an
// API error body arrives (one line of JSON, no newline).
$lines = [];
$tail3 = '';
$splitter3 = makeLineSplitter(function ($l) use (&$lines) { $lines[] = $l; }, $tail3);
$splitter3(null, "first\n{\"error\":1}");
check('line splitter leaves an unterminated final line for the caller to flush',
      $lines === ['first'] && $tail3 === '{"error":1}', json_encode([$lines, $tail3]));

// ---------- Anthropic streaming ----------
$deltas = []; $times = [];
$start = microtime(true);
list($code, $data, $err) = callAnthropicApiStreaming(
    ['model' => 'claude-test-1', 'max_tokens' => 100, 'messages' => []],
    'fake-key',
    function ($p) use (&$deltas, &$times, $start) { $deltas[] = $p; $times[] = microtime(true) - $start; },
    $BASE . '/anthropic'
);
check('anthropic: HTTP 200', $code === 200, "got $code $err");
check('anthropic: text assembled in order', ($data['content'][0]['text'] ?? '') === $EXPECTED);
check('anthropic: deltas delivered separately', count($deltas) === 6, 'count=' . count($deltas));
check('anthropic: deltas arrived over time, not at once',
      count($times) > 1 && (end($times) - $times[0]) > 0.3,
      'spread=' . (count($times) > 1 ? round(end($times) - $times[0], 2) : 0));
check('anthropic: model captured', ($data['model'] ?? '') === 'claude-test-1');
check('anthropic: stop_reason captured', ($data['stop_reason'] ?? '') === 'end_turn');
check('anthropic: usage captured', ($data['usage']['input_tokens'] ?? 0) === 11
      && ($data['usage']['output_tokens'] ?? 0) === 27);

// ---------- OpenAI-compatible streaming ----------
$deltas = [];
list($code, $data, $err) = callOpenAiCompatibleApiStreaming(
    $BASE . '/openai',
    ['model' => 'fake-model-1', 'messages' => [], 'max_tokens' => 100],
    'fake-key',
    function ($p) use (&$deltas) { $deltas[] = $p; }
);
check('openai: HTTP 200', $code === 200, "got $code $err");
check('openai: text assembled in order', ($data['content'][0]['text'] ?? '') === $EXPECTED);
check('openai: stop_reason mapped to end_turn', ($data['stop_reason'] ?? '') === 'end_turn');
check('openai: usage captured via stream_options', ($data['usage']['output_tokens'] ?? 0) === 27);

// ---------- usage missing -> estimation ----------
$deltas = [];
list($code, $data, $err) = callOpenAiCompatibleApiStreaming(
    $BASE . '/openai-nousage',
    ['model' => 'fake-model-1', 'messages' => [], 'max_tokens' => 100],
    'fake-key',
    function ($p) use (&$deltas) { $deltas[] = $p; }
);
$reported = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);
check('openai: absent usage reports zero rather than a wrong number', $reported === 0);
$est = estimateUsage('a prompt of some length', $EXPECTED);
check('estimateUsage produces a non-zero estimate', $est['output_tokens'] > 0 && $est['input_tokens'] > 0);

// ---------- split lines ----------
$deltas = [];
list($code, $data, $err) = callOpenAiCompatibleApiStreaming(
    $BASE . '/split',
    ['model' => 'fake-model-1', 'messages' => [], 'max_tokens' => 100],
    'fake-key',
    function ($p) use (&$deltas) { $deltas[] = $p; }
);
check('split writes: text still assembled correctly', ($data['content'][0]['text'] ?? '') === $EXPECTED,
      json_encode($data['content'][0]['text'] ?? null));

// ---------- upstream error before any delta ----------
$deltas = [];
list($code, $data, $err) = callAnthropicApiStreaming(
    ['model' => 'claude-test-1', 'max_tokens' => 100, 'messages' => []],
    'fake-key',
    function ($p) use (&$deltas) { $deltas[] = $p; },
    $BASE . '/error'
);
check('error: reported as HTTP 400', $code === 400, "got $code");
check('error: no deltas emitted, so healing can still retry', count($deltas) === 0);
check('error: upstream error body preserved',
      strpos($data['error']['message'] ?? '', 'temperature') !== false,
      json_encode($data));

echo "\n" . str_repeat('=', 60) . "\nPassed: $pass  Failed: $fail\n";
exit($fail ? 1 : 0);

<?php
/**
 * PMC2 Claude Proxy — Pasco Municipal Code AI Reference (Anthropic Claude)
 * Based on pmc1/api-proxy.php architecture; rewritten for Anthropic Messages API
 * with automatic prompt caching (no cron, no Files API, no cache-create.php needed).
 *
 * Caching strategy:
 *   - System prompt:    cache_control=ephemeral on the MASTER_RUBRIC block
 *   - PMC document:     cache_control=ephemeral on the first user content block
 *   - Claude caches automatically; first request writes cache, subsequent reads it at 90% off
 *   - No storage fee — Claude's prompt caching has no separate hourly charge
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/claudekey.php';

// PMC clean text — shared with pmc1 (single source of truth)
define('PMC_FILE', __DIR__ . '/../pmc1/Pasco-Municipal-Code-clean.txt');

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

// ── TUNABLE COST CONTROLS ────────────────────────────────────────────────────
define('MAX_HISTORY_CHARS', 80_000);
define('MAX_OUTPUT_TOKENS', 4000);

// ── MODEL MAP ────────────────────────────────────────────────────────────────
// Haiku 4.5 has only a 200k context window — too small for the 938k-token PMC.
// Only Sonnet 4.6 and Opus 4.6 have the 1M context window needed.
define('MODEL_MAP_JSON', json_encode([
    'claude-sonnet-4-6' => 'claude-sonnet-4-6',
    'claude-opus-4-6'   => 'claude-opus-4-6',
]));

// ── STREAMING HANDLER ────────────────────────────────────────────────────────
function handle_stream($data, $secretsFile) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Content-Encoding: identity');
    header('Connection: keep-alive');
    if (function_exists('apache_setenv')) { apache_setenv('no-gzip', '1'); }
    while (ob_get_level()) { ob_end_flush(); }

    if (!file_exists($secretsFile)) {
        echo "data: " . json_encode(['error' => 'API key file missing']) . "\n\n";
        flush(); return;
    }
    $secrets = require $secretsFile;
    $apiKey  = $secrets['ANTHROPIC_API_KEY'] ?? null;
    if (!$apiKey) {
        echo "data: " . json_encode(['error' => 'ANTHROPIC_API_KEY missing in secrets file']) . "\n\n";
        flush(); return;
    }

    // ── Model validation ─────────────────────────────────────────────────────
    $modelMap    = json_decode(MODEL_MAP_JSON, true);
    $requested   = $data['model'] ?? 'claude-sonnet-4-6';
    $actualModel = $modelMap[$requested] ?? 'claude-sonnet-4-6';

    // ── Load PMC document ────────────────────────────────────────────────────
    $pmcText = null;
    if (file_exists(PMC_FILE)) {
        $pmcText = file_get_contents(PMC_FILE);
    }

    // ── Build messages array ─────────────────────────────────────────────────
    // Claude requires alternating user/assistant turns.
    // We inject the PMC document (with cache_control) into the FIRST user message.
    // Subsequent user messages are plain text — the cache is already warm.
    $messages    = [];
    $pmcInjected = false;

    if (isset($data['messages']) && is_array($data['messages'])) {
        // Payload size guard
        if (strlen(json_encode($data['messages'])) > MAX_HISTORY_CHARS) {
            echo "data: " . json_encode(['error' => 'History too large. Please refresh and start a new session.']) . "\n\n";
            echo "data: [DONE]\n\n";
            flush(); return;
        }

        foreach ($data['messages'] as $msg) {
            $role = $msg['role'];
            // Normalise content from either string or parts/content array
            $text = '';
            if (is_string($msg['content'] ?? null)) {
                $text = $msg['content'];
            } elseif (is_array($msg['content'] ?? null)) {
                foreach ($msg['content'] as $block) {
                    $text .= $block['text'] ?? '';
                }
            } elseif (is_array($msg['parts'] ?? null)) {
                // pmc1-style parts array (fallback compatibility)
                foreach ($msg['parts'] as $p) { $text .= $p['text'] ?? ''; }
            }

            if ($role === 'user' && !$pmcInjected && $pmcText !== null) {
                // First user message: prepend the PMC with cache_control
                $messages[] = [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'          => 'text',
                            'text'          => "The following is the complete text of the Pasco Municipal Code:\n\n" . $pmcText,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                        [
                            'type' => 'text',
                            'text' => $text,
                        ],
                    ],
                ];
                $pmcInjected = true;
            } else {
                $messages[] = [
                    'role'    => ($role === 'model') ? 'assistant' : $role,
                    'content' => $text,
                ];
            }
        }
    }

    if (empty($messages)) {
        echo "data: " . json_encode(['error' => 'No messages provided.']) . "\n\n";
        echo "data: [DONE]\n\n";
        flush(); return;
    }

    // ── System prompt with cache_control ─────────────────────────────────────
    // Caching the system prompt saves ~90% on those tokens every subsequent call.
    $systemText = $data['system'] ?? 'You are a municipal code reference assistant.';
    $systemBlocks = [
        [
            'type'          => 'text',
            'text'          => $systemText,
            'cache_control' => ['type' => 'ephemeral'],
        ]
    ];

    // ── Build API payload ─────────────────────────────────────────────────────
    $payload = [
        'model'      => $actualModel,
        'max_tokens' => MAX_OUTPUT_TOKENS,
        'stream'     => true,
        'system'     => $systemBlocks,
        'messages'   => $messages,
    ];

    // ── Call Anthropic Messages API ───────────────────────────────────────────
    $url = 'https://api.anthropic.com/v1/messages';
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'x-api-key: '           . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    $rawBody   = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno || $httpCode !== 200) {
        $errMsg = $curlErrno
            ? "curl error $curlErrno: $curlError"
            : "Anthropic returned HTTP $httpCode";
        // Try to extract a message from the body
        $bodyData = json_decode($rawBody ?? '', true);
        if (isset($bodyData['error']['message'])) {
            $errMsg = $bodyData['error']['message'];
        }
        echo "data: " . json_encode(['error' => $errMsg]) . "\n\n";
        echo "data: [DONE]\n\n";
        flush(); return;
    }

    // ── Parse Claude SSE events ───────────────────────────────────────────────
    // Claude SSE format uses both "event:" and "data:" lines.
    // We only need the data: lines; event: lines are informational.
    //
    // Key events:
    //   message_start        → initial usage (cache_read_input_tokens, cache_creation_input_tokens)
    //   content_block_delta  → text delta (delta.type === 'text_delta')
    //   message_delta        → final output token count
    //   message_stop         → stream end
    $cacheRead    = 0;
    $cacheWrite   = 0;
    $inputTokens  = 0;
    $outputTokens = 0;

    foreach (preg_split('/\r?\n/', $rawBody) as $line) {
        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $jsonStr = substr($line, 6);
        $event   = json_decode($jsonStr, true);
        if ($event === null) continue;

        switch ($event['type'] ?? '') {
            case 'message_start':
                $u            = $event['message']['usage'] ?? [];
                $inputTokens  = (int)($u['input_tokens']                  ?? 0);
                $cacheRead    = (int)($u['cache_read_input_tokens']        ?? 0);
                $cacheWrite   = (int)($u['cache_creation_input_tokens']    ?? 0);
                break;

            case 'content_block_delta':
                if (($event['delta']['type'] ?? '') === 'text_delta') {
                    $text = $event['delta']['text'] ?? '';
                    if ($text !== '') {
                        echo "data: " . json_encode(['text' => $text]) . "\n\n";
                    }
                }
                break;

            case 'message_delta':
                $outputTokens = (int)($event['usage']['output_tokens'] ?? 0);
                break;

            case 'error':
                $errMsg = $event['error']['message'] ?? 'Unknown Claude error';
                echo "data: " . json_encode(['error' => $errMsg]) . "\n\n";
                break;
        }
    }

    // ── Emit meta event (cache badge in index.html) ───────────────────────────
    // For Claude: cachedTokens = cache_read_input_tokens (hits cost 10% of normal)
    //             cacheWrite   = cache_creation_input_tokens (cost 125% first time)
    echo "data: " . json_encode(['meta' => [
        'cachedTokens' => $cacheRead,
        'cacheWrite'   => $cacheWrite,
        'inTokens'     => $inputTokens,
        'outTokens'    => $outputTokens,
    ]]) . "\n\n";

    echo "data: [DONE]\n\n";
    flush();

    // ── Usage logging ─────────────────────────────────────────────────────────
    $sessionId  = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
    $cacheFlag  = $cacheRead > 0 ? "CACHE_HIT:{$cacheRead}" : ($cacheWrite > 0 ? "CACHE_WRITE:{$cacheWrite}" : "CACHE_MISS");
    $logLine    = date('Y-m-d H:i:s') . " | $sessionId | $actualModel | In:$inputTokens | Out:$outputTokens | CacheRead:$cacheRead | CacheWrite:$cacheWrite | $cacheFlag\n";
    file_put_contents(__DIR__ . '/claude_usage.log', $logLine, FILE_APPEND);
}

// ── Route dispatcher ──────────────────────────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);

if (isset($_GET['stream']) && $_GET['stream'] === '1') {
    handle_stream($data, $secretsFile);
    exit;
}

// ── Non-streaming routes ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// --- QUERY LOGGING ---
if (isset($data['action']) && $data['action'] === 'log_query') {
    $sessionId  = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
    $logContent = $data['log'] ?? '';

    if (!is_dir('query_logs')) { mkdir('query_logs', 0777, true); }

    $htaccess = 'query_logs/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $logFilename  = 'query_logs/' . date('Y-m') . '.txt';
    $timestamp    = date('Y-m-d H:i:s');
    $formattedLog = "--- $timestamp | $sessionId ---\n" . $logContent . "\n";
    file_put_contents($logFilename, $formattedLog, FILE_APPEND);

    if (isset($data['ttfb_ms']) && is_numeric($data['ttfb_ms'])) {
        $ttfbMs  = (int)$data['ttfb_ms'];
        $ttfbLog = date('Y-m-d H:i:s') . " | $sessionId | TTFB:{$ttfbMs}ms\n";
        file_put_contents(__DIR__ . '/claude_usage.log', $ttfbLog, FILE_APPEND);
    }

    echo json_encode(['success' => true]);
    exit;
}

// --- NON-STREAMING CHAT FALLBACK ---
if (!file_exists($secretsFile)) send_error('API key file missing.');
$secrets = require $secretsFile;
$apiKey  = $secrets['ANTHROPIC_API_KEY'] ?? null;
if (!$apiKey) send_error('ANTHROPIC_API_KEY missing in secrets file.');

$modelMap    = json_decode(MODEL_MAP_JSON, true);
$requested   = $data['model'] ?? 'claude-sonnet-4-6';
$actualModel = $modelMap[$requested] ?? 'claude-sonnet-4-6';

// Build messages (same logic as streaming)
$pmcText     = file_exists(PMC_FILE) ? file_get_contents(PMC_FILE) : null;
$messages    = [];
$pmcInjected = false;

if (strlen(json_encode($data['messages'] ?? [])) > MAX_HISTORY_CHARS) {
    send_error('History too large. Please refresh and start a new session.');
}

foreach ($data['messages'] ?? [] as $msg) {
    $role = $msg['role'];
    $text = '';
    if (is_string($msg['content'] ?? null)) { $text = $msg['content']; }
    elseif (is_array($msg['content'] ?? null)) { foreach ($msg['content'] as $b) { $text .= $b['text'] ?? ''; } }
    elseif (is_array($msg['parts'] ?? null))   { foreach ($msg['parts'] as $p)   { $text .= $p['text'] ?? ''; } }

    if ($role === 'user' && !$pmcInjected && $pmcText !== null) {
        $messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => "The following is the complete text of the Pasco Municipal Code:\n\n" . $pmcText, 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $text],
            ],
        ];
        $pmcInjected = true;
    } else {
        $messages[] = ['role' => ($role === 'model') ? 'assistant' : $role, 'content' => $text];
    }
}

$systemText   = $data['system'] ?? 'You are a municipal code reference assistant.';
$systemBlocks = [['type' => 'text', 'text' => $systemText, 'cache_control' => ['type' => 'ephemeral']]];

$payload = [
    'model'      => $actualModel,
    'max_tokens' => MAX_OUTPUT_TOKENS,
    'system'     => $systemBlocks,
    'messages'   => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER,     [
    'Content-Type: application/json',
    'x-api-key: '         . $apiKey,
    'anthropic-version: 2023-06-01',
    'anthropic-beta: prompt-caching-2024-07-31',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Usage logging
$responseData = json_decode($response, true);
$usage        = $responseData['usage'] ?? [];
$sessionId    = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
$inTokens     = $usage['input_tokens']               ?? 0;
$outTokens    = $usage['output_tokens']               ?? 0;
$cacheRead    = $usage['cache_read_input_tokens']     ?? 0;
$cacheWrite   = $usage['cache_creation_input_tokens'] ?? 0;
$cacheFlag    = $cacheRead > 0 ? "CACHE_HIT:$cacheRead" : ($cacheWrite > 0 ? "CACHE_WRITE:$cacheWrite" : "CACHE_MISS");
$logLine      = date('Y-m-d H:i:s') . " | $sessionId | $actualModel | In:$inTokens | Out:$outTokens | CacheRead:$cacheRead | CacheWrite:$cacheWrite | $cacheFlag\n";
file_put_contents(__DIR__ . '/claude_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;

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

// PMC text — prefer local copy in pmc2 folder; fall back to pmc1 if needed.
// Supports both clean .txt (preferred) and .html (tags stripped on load).
function load_pmc_text(): ?string {
    $candidates = [
        __DIR__ . '/Pasco-Municipal-Code-clean.txt',   // local clean text (ideal)
        __DIR__ . '/Pasco-Municipal-Code-clean.html',  // local HTML (stripped)
        __DIR__ . '/../pmc1/Pasco-Municipal-Code-clean.txt',  // pmc1 fallback
        __DIR__ . '/../pmc1/Pasco-Municipal-Code-clean.html', // pmc1 HTML fallback
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            if ($raw === false) continue;
            // Strip HTML tags and normalise whitespace when loading an .html file
            if (str_ends_with($path, '.html')) {
                $raw = preg_replace('/\s+/', ' ', strip_tags($raw));
            }
            return $raw;
        }
    }
    return null;
}

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
    // Disable ALL layers of PHP/SAPI output buffering.
    // On LiteSpeed (this server's SAPI), ini_set is the reliable way;
    // X-Accel-Buffering: no is nginx-only and ignored here.
    @ini_set('output_buffering',        '0');
    @ini_set('implicit_flush',          '1');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) { ob_end_clean(); }   // discard, not flush (nothing yet)

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');             // nginx (harmless on LiteSpeed)
    header('X-LiteSpeed-Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Encoding: identity');
    header('Connection: keep-alive');
    if (function_exists('apache_setenv')) { apache_setenv('no-gzip', '1'); }

    // Send an init ping IMMEDIATELY so LiteSpeed sees live data and does not
    // treat this as an idle/pending response while we wait for Anthropic TTFB.
    echo ": init\n\n";
    flush();

    if (!file_exists($secretsFile)) {
        echo "data: " . json_encode(['error' => 'API key file missing']) . "\n\n";
        echo "data: [DONE]\n\n"; flush(); return;
    }
    $secrets = require $secretsFile;
    // Support both: return ['ANTHROPIC_API_KEY' => '...'] and $ANTHROPIC_API_KEY = '...';
    if (is_array($secrets)) {
        $apiKey = $secrets['ANTHROPIC_API_KEY'] ?? null;
    } else {
        $apiKey = $ANTHROPIC_API_KEY ?? null;
    }
    if (!$apiKey) {
        echo "data: " . json_encode(['error' => 'ANTHROPIC_API_KEY missing in secrets file']) . "\n\n";
        echo "data: [DONE]\n\n"; flush(); return;
    }

    // ── Model validation ─────────────────────────────────────────────────────
    $modelMap    = json_decode(MODEL_MAP_JSON, true);
    $requested   = $data['model'] ?? 'claude-sonnet-4-6';
    $actualModel = $modelMap[$requested] ?? 'claude-sonnet-4-6';

    // ── Debug logging (writes to claude_debug.log regardless of stream outcome)
    $dlog = __DIR__ . '/claude_debug.log';
    $dpfx = date('H:i:s') . ' ';
    file_put_contents($dlog, $dpfx . "START model=$actualModel\n", FILE_APPEND);

    // ── Load PMC document ────────────────────────────────────────────────────
    $pmcText = load_pmc_text();
    file_put_contents($dlog, $dpfx . "PMC=" . ($pmcText === null ? 'NULL' : strlen($pmcText).'B') . "\n", FILE_APPEND);

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

    // ── Call Anthropic Messages API — real-time streaming via CURLOPT_WRITEFUNCTION
    // IMPORTANT: Do NOT use CURLOPT_RETURNTRANSFER = true here.
    // The PMC is ~938k tokens; Anthropic takes 30–90s to complete the full response.
    // Buffering the whole response before forwarding causes nginx fastcgi_read_timeout
    // to kill the connection (~60s on cPanel), resulting in an empty stream and
    // "No response received" on the client.
    // Instead, we forward each SSE chunk to the browser the instant it arrives.
    $url = 'https://api.anthropic.com/v1/messages';

    // Shared mutable state for the write callback (passed by reference)
    $st = [
        'buf'        => '',      // incomplete SSE line buffer
        'cacheRead'  => 0,
        'cacheWrite' => 0,
        'inTok'      => 0,
        'outTok'     => 0,
        'errBody'    => '',      // collects body on non-200 responses
        'httpCode'   => 0,
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    file_put_contents($dlog, $dpfx . "PAYLOAD_SIZE=" . ($jsonPayload === false ? 'JSON_FAIL' : strlen($jsonPayload).'B') . "\n", FILE_APPEND);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'x-api-key: '           . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);   // ← must be false for write callback
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        180);

    // Heartbeat: send a SSE comment every ~4 s while curl is waiting for Anthropic
    // to start streaming (TTFB can be 10–20 s for a 925k-token input).
    // This prevents LiteSpeed's idle-connection timeout from killing the response.
    // SSE comment lines (": hb") are silently ignored by the frontend parser.
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (...$_) {
        static $last = 0;
        $now = microtime(true);
        if ($now - $last > 4.0) {
            echo ": hb\n\n";
            @ob_flush(); flush();
            $last = $now;
        }
        return 0;   // 0 = continue transfer
    });

    // Capture HTTP status code from response headers
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$st) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $st['httpCode'] = (int)$m[1];
        }
        return strlen($header);
    });

    // Process each chunk as it arrives — forward text deltas immediately
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$st) {
        // On non-200 responses, collect the error body for later reporting
        if ($st['httpCode'] !== 0 && $st['httpCode'] !== 200) {
            $st['errBody'] .= $chunk;
            return strlen($chunk);
        }

        $st['buf'] .= $chunk;

        // Process every complete line in the accumulated buffer
        while (($nl = strpos($st['buf'], "\n")) !== false) {
            $line        = rtrim(substr($st['buf'], 0, $nl), "\r");
            $st['buf']   = substr($st['buf'], $nl + 1);

            if (strncmp($line, 'data: ', 6) !== 0) continue;
            $jsonStr = substr($line, 6);
            $event   = json_decode($jsonStr, true);
            if ($event === null) continue;

            switch ($event['type'] ?? '') {
                case 'message_start':
                    $u              = $event['message']['usage'] ?? [];
                    $st['inTok']    = (int)($u['input_tokens']               ?? 0);
                    $st['cacheRead']= (int)($u['cache_read_input_tokens']    ?? 0);
                    $st['cacheWrite']=(int)($u['cache_creation_input_tokens']?? 0);
                    break;

                case 'content_block_delta':
                    if (($event['delta']['type'] ?? '') === 'text_delta') {
                        $txt = $event['delta']['text'] ?? '';
                        if ($txt !== '') {
                            echo "data: " . json_encode(['text' => $txt]) . "\n\n";
                            @ob_flush(); flush();
                        }
                    }
                    break;

                case 'message_delta':
                    $st['outTok'] = (int)($event['usage']['output_tokens'] ?? 0);
                    break;

                case 'error':
                    $errMsg = $event['error']['message'] ?? 'Unknown Claude error';
                    echo "data: " . json_encode(['error' => $errMsg]) . "\n\n";
                    @ob_flush(); flush();
                    break;
            }
        }
        return strlen($chunk);
    });

    file_put_contents($dlog, $dpfx . "CURL_START\n", FILE_APPEND);
    curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    file_put_contents($dlog, $dpfx . "CURL_END errno=$curlErrno httpCode={$st['httpCode']} inTok={$st['inTok']} outTok={$st['outTok']} cacheRead={$st['cacheRead']} cacheWrite={$st['cacheWrite']}\n", FILE_APPEND);

    // ── Report any transport-level errors ────────────────────────────────────
    if ($curlErrno) {
        echo "data: " . json_encode(['error' => "curl error $curlErrno: $curlError"]) . "\n\n";
    } elseif ($st['httpCode'] !== 200) {
        $bodyData = json_decode($st['errBody'], true);
        $errMsg   = $bodyData['error']['message'] ?? "Anthropic returned HTTP {$st['httpCode']}";
        echo "data: " . json_encode(['error' => $errMsg]) . "\n\n";
    }

    // ── Emit meta event (cache badge in index.html) ───────────────────────────
    echo "data: " . json_encode(['meta' => [
        'cachedTokens' => $st['cacheRead'],
        'cacheWrite'   => $st['cacheWrite'],
        'inTokens'     => $st['inTok'],
        'outTokens'    => $st['outTok'],
    ]]) . "\n\n";

    echo "data: [DONE]\n\n";
    flush();
    file_put_contents($dlog, $dpfx . "DONE\n", FILE_APPEND);

    // ── Usage logging ─────────────────────────────────────────────────────────
    $sessionId  = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
    $cacheFlag  = $st['cacheRead'] > 0
        ? "CACHE_HIT:{$st['cacheRead']}"
        : ($st['cacheWrite'] > 0 ? "CACHE_WRITE:{$st['cacheWrite']}" : "CACHE_MISS");
    $logLine    = date('Y-m-d H:i:s') . " | $sessionId | $actualModel"
        . " | In:{$st['inTok']} | Out:{$st['outTok']}"
        . " | CacheRead:{$st['cacheRead']} | CacheWrite:{$st['cacheWrite']} | $cacheFlag\n";
    file_put_contents(__DIR__ . '/claude_usage.log', $logLine, FILE_APPEND);
}

// ── Route dispatcher ──────────────────────────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);

// ── ?stream=test — pure PHP→Browser streaming sanity check (no Anthropic call)
// Visit https://psd1.net/pmc2/api-proxy.php?stream=test in a browser.
// If you see text appearing word-by-word, PHP→LiteSpeed→Browser streaming works.
if (isset($_GET['stream']) && $_GET['stream'] === 'test') {
    @ini_set('output_buffering',        '0');
    @ini_set('implicit_flush',          '1');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-LiteSpeed-Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Encoding: identity');
    header('Connection: keep-alive');
    echo ": init\n\n"; flush();
    $words = ['PHP', 'streaming', 'to', 'LiteSpeed', 'is', 'working', 'correctly!'];
    foreach ($words as $i => $word) {
        sleep(1);
        echo "data: " . json_encode(['text' => $word . ' ']) . "\n\n";
        @ob_flush(); flush();
    }
    echo "data: " . json_encode(['meta' => ['cachedTokens'=>0,'cacheWrite'=>0,'inTokens'=>0,'outTokens'=>7]]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

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
if (is_array($secrets)) {
    $apiKey = $secrets['ANTHROPIC_API_KEY'] ?? null;
} else {
    $apiKey = $ANTHROPIC_API_KEY ?? null;
}
if (!$apiKey) send_error('ANTHROPIC_API_KEY missing in secrets file.');

$modelMap    = json_decode(MODEL_MAP_JSON, true);
$requested   = $data['model'] ?? 'claude-sonnet-4-6';
$actualModel = $modelMap[$requested] ?? 'claude-sonnet-4-6';

// Build messages (same logic as streaming)
$pmcText     = load_pmc_text();
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
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload, JSON_UNESCAPED_UNICODE));
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

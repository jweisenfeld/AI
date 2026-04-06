<?php
/**
 * lotf1/api-proxy.php
 *
 * Gemini proxy for the Lord of the Flies Text Navigator.
 * Based on pmc1/api-proxy.php — adapted for PDF document and two-model setup.
 *
 * Key differences from pmc1:
 *  - Document: Lord-of-the-Flies.pdf (application/pdf), not HTML
 *  - System prompt is hardcoded server-side (not read from client)
 *  - Models: gemini-2.5-flash-lite (Quick) and gemini-2.5-flash (Full)
 *  - Explicit cache file names prefixed with 'lotf1_' to avoid collision with pmc1
 *  - Cache URI stored in .secrets/lotf1_cache_name.txt (separate from pmc1)
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$cacheNameFile = $accountRoot . '/.secrets/lotf1_cache_name.txt';

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// TUNABLE COST CONTROLS
// ══════════════════════════════════════════════════════════════
define('MAX_HISTORY_CHARS', 40_000);  // text retrieval sessions are short

// ── Explicit Context Cache ────────────────────────────────────────────────────
define('EXPLICIT_CACHE_TTL', 12 * 3600);
define('EXPLICIT_CACHE_MODELS_JSON', json_encode(['gemini-2.5-flash-lite', 'gemini-2.5-flash']));

// ── System prompt — hardcoded server-side, not read from client ───────────────
define('LOTF_SYSTEM_PROMPT', <<<'PROMPT'
### ROLE
You are a text locator for William Golding's novel "Lord of the Flies" (1954).
Your only job is to help students find passages in the book. You locate scenes,
quote text, and cite chapters. You do not interpret, analyze, or explain.

### WHAT YOU DO
- Find and quote passages relevant to the student's question
- Cite the chapter by number and Golding's chapter title (e.g., "Chapter 2: Fire on the Mountain")
- Return all clearly relevant passages, quoted directly from the text
- For "where is X scene?" questions: locate the scene and quote the key lines
- For factual questions ("where do they use Piggy's glasses to start a fire?"): find and quote the passage

### WHAT YOU DO NOT DO — HARD RULES
- Never summarize the book, any chapter, any character, or any event in your own words
- Never explain themes, symbols, or motifs
- Never answer "why" questions (why did a character do something, why does something happen)
- Never analyze character motivations, personalities, or development
- Never answer leading questions that presuppose a literary judgment
  (e.g., "explain the savagery of Jack", "where does Ralph show weak leadership")
- Never confirm or validate a student's interpretation

### HANDLING BORDERLINE QUESTIONS
If a question contains an embedded interpretive judgment ("where is Piggy especially savage?",
"where does Jack become evil?"), do not validate or argue the premise. Instead respond:
"I can find passages where [neutral description of the event/behavior]. Here are the relevant
passages — you can draw your own conclusion:"
Then quote the passages without comment.

### WHEN TO DECLINE
Decline briefly when the question is purely interpretive with no locatable textual anchor:
- "What does the conch symbolize?"
- "What is the theme of the novel?"
- "Why is Simon important?"
Respond: "That's an interpretation question — I can help you find passages in the book,
but the meaning is for you to work out. Try asking where a specific event or scene occurs."

### RESPONSE FORMAT
For each relevant passage:
**Chapter [number]: "[Chapter title]"**
> [exact quoted text from the book]

If multiple passages are relevant, list them in order of appearance.
Add nothing after the quote — no commentary, no "this shows that...", no analysis.

### IMPORTANT
You have the full text of Lord of the Flies. When quoting, use the exact words from the book.
Always cite the chapter. Do not paraphrase — quote directly.
PROMPT);

/**
 * Returns a valid explicit cachedContent name for $model, creating one if needed.
 * $projectSlug is included in the filename to avoid collision with other projects
 * (e.g. pmc1) that share the same .secrets/ directory.
 */
function get_or_create_explicit_cache(string $model, string $fileUri, string $fileMime, string $apiKey, string $accountRoot, string $systemText, string $projectSlug = 'lotf1'): ?string {
    $safeModel  = preg_replace('/[^a-z0-9\-]/', '-', $model);
    $nameFile   = $accountRoot . '/.secrets/gemini_explicit_cache_' . $projectSlug . '_' . $safeModel . '.txt';
    $expiryFile = $nameFile . '.expires';
    $lockFile   = $nameFile . '.lock';

    // ── Valid cache already exists? ───────────────────────────────────────────
    if (file_exists($nameFile) && file_exists($expiryFile)) {
        $expireTime = (int)trim(file_get_contents($expiryFile));
        if (time() < $expireTime) {
            $cacheName = trim(file_get_contents($nameFile));
            if (!empty($cacheName)) return $cacheName;
        }
    }

    // ── Acquire file lock — one creator at a time ─────────────────────────────
    $lock = fopen($lockFile, 'c');
    if (!$lock) return null;

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        flock($lock, LOCK_EX);
        fclose($lock);
        if (file_exists($nameFile) && file_exists($expiryFile)) {
            $expireTime = (int)trim(file_get_contents($expiryFile));
            if (time() < $expireTime) {
                $cacheName = trim(file_get_contents($nameFile));
                if (!empty($cacheName)) return $cacheName;
            }
        }
        return null;
    }

    // ── We hold the lock — create the cache ──────────────────────────────────
    $createUrl = "https://generativelanguage.googleapis.com/v1beta/cachedContents?key=$apiKey";
    $createPayload = [
        'model'    => "models/$model",
        'contents' => [[
            'role'  => 'user',
            'parts' => [['fileData' => ['mimeType' => $fileMime, 'fileUri' => $fileUri]]]
        ]],
        'systemInstruction' => ['parts' => [['text' => $systemText]]],
        'ttl' => EXPLICIT_CACHE_TTL . 's',
    ];

    $ch = curl_init($createUrl);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($createPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $cacheName = null;
    $result    = json_decode($body, true);
    if ($code === 200 && isset($result['name'])) {
        $cacheName  = $result['name'];
        $expireTime = time() + EXPLICIT_CACHE_TTL - 60;
        file_put_contents($nameFile,   $cacheName);
        file_put_contents($expiryFile, (string)$expireTime);
        file_put_contents(__DIR__ . '/gemini_usage.log',
            date('Y-m-d H:i:s') . " | EXPLICIT_CACHE_CREATED | $model | $cacheName | expires:" . date('Y-m-d H:i:s', $expireTime) . "\n",
            FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . '/gemini_usage.log',
            date('Y-m-d H:i:s') . " | EXPLICIT_CACHE_FAIL | $model | HTTP:$code | " . substr($body ?? '', 0, 200) . "\n",
            FILE_APPEND);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $cacheName;
}

// --- STREAMING HANDLER ---
function handle_stream($data, $secretsFile, $cacheNameFile) {

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Content-Encoding: identity');
    header('Connection: keep-alive');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }

    while (ob_get_level()) { ob_end_flush(); }

    if (!file_exists($secretsFile)) {
        echo "data: " . json_encode(['error' => 'API Key file missing']) . "\n\n";
        flush();
        return;
    }
    require_once($secretsFile);

    $modelMap = [
        'gemini-2.5-flash-lite' => 'gemini-2.5-flash-lite',
        'gemini-2.5-flash'      => 'gemini-2.5-flash',
    ];
    $requested   = $data['model'] ?? 'gemini-2.5-flash-lite';
    $actualModel = $modelMap[$requested] ?? 'gemini-2.5-flash-lite';

    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . $actualModel
         . ":streamGenerateContent?alt=sse&key=" . trim($GEMINI_API_KEY);

    // ── Files API URI lookup ──────────────────────────────────────────────────
    $fileUri      = null;
    $fileMimeType = 'application/pdf';
    if (file_exists($cacheNameFile)) {
        $saved = trim(file_get_contents($cacheNameFile));
        if (!empty($saved) && strpos($saved, 'generativelanguage.googleapis.com') !== false) {
            $expiryFile = $cacheNameFile . '.expires';
            $expired = true;
            if (file_exists($expiryFile)) {
                $expireTime = (int)trim(file_get_contents($expiryFile));
                $expired = (time() > $expireTime);
            }
            if (!$expired) $fileUri = $saved;
        }
    }

    // ── Explicit cache lookup ─────────────────────────────────────────────────
    $explicitCacheName = null;
    $explicitModels    = json_decode(EXPLICIT_CACHE_MODELS_JSON, true);
    if ($fileUri !== null && in_array($actualModel, $explicitModels)) {
        $accountRootLocal  = dirname(dirname($cacheNameFile));
        $explicitCacheName = get_or_create_explicit_cache(
            $actualModel, $fileUri, $fileMimeType, trim($GEMINI_API_KEY), $accountRootLocal,
            LOTF_SYSTEM_PROMPT
        );
    }

    // ── Build contents array ──────────────────────────────────────────────────
    $contents = [];
    if (isset($data['messages'])) {
        foreach ($data['messages'] as $m) {
            $role  = ($m['role'] === 'assistant' || $m['role'] === 'model') ? 'model' : 'user';
            $parts = [];
            if (is_array($m['parts'])) {
                foreach ($m['parts'] as $p) {
                    if (isset($p['text'])) $parts[] = ['text' => $p['text']];
                }
            } elseif (isset($m['content']) && is_string($m['content'])) {
                $parts[] = ['text' => $m['content']];
            }
            if (!empty($parts)) $contents[] = ['role' => $role, 'parts' => $parts];
        }
    }

    if (strlen(json_encode($contents)) > MAX_HISTORY_CHARS) {
        echo "data: " . json_encode(['error' => 'History too large. Please refresh and start a new session.']) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        return;
    }

    // ── Build payload ─────────────────────────────────────────────────────────
    $systemInstruction = ['parts' => [['text' => LOTF_SYSTEM_PROMPT]]];
    $generationConfig  = ['maxOutputTokens' => 3000];

    if ($explicitCacheName !== null) {
        // System prompt is baked into the cache — do NOT send it here
        $payload = [
            'cachedContent'    => $explicitCacheName,
            'contents'         => $contents,
            'generationConfig' => $generationConfig,
        ];
    } else {
        if ($fileUri !== null) {
            array_unshift($contents, [
                'role'  => 'user',
                'parts' => [['file_data' => ['mime_type' => $fileMimeType, 'file_uri' => $fileUri]]]
            ]);
        }
        $payload = [
            'contents'          => $contents,
            'systemInstruction' => $systemInstruction,
            'generationConfig'  => $generationConfig,
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        120);
    $rawBody   = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $curlInfo  = curl_getinfo($ch);
    curl_close($ch);

    if ($curlErrno || $curlInfo['http_code'] !== 200) {
        $errMsg = $curlErrno ? "curl error $curlErrno: $curlError"
                             : "Gemini returned HTTP " . $curlInfo['http_code'];
        echo "data: " . json_encode(['error' => $errMsg]) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        return;
    }

    // ── Cold-start auto-retry ─────────────────────────────────────────────────
    if ($fileUri !== null && $explicitCacheName === null) {
        $firstPassOut    = 0;
        $firstPassCached = 0;
        foreach (preg_split('/\r?\n/', $rawBody) as $rl) {
            if (strncmp($rl, 'data: ', 6) !== 0) continue;
            $rp = json_decode(substr($rl, 6), true);
            if ($rp === null) continue;
            if (isset($rp['usageMetadata'])) {
                $firstPassOut    = (int)($rp['usageMetadata']['candidatesTokenCount']    ?? 0);
                $firstPassCached = (int)($rp['usageMetadata']['cachedContentTokenCount'] ?? 0);
            }
        }
        if ($firstPassOut <= 5 && $firstPassCached === 0) {
            file_put_contents(__DIR__ . '/gemini_usage.log',
                date('Y-m-d H:i:s') . " | AUTO_RETRY | cold-start dud (Out:{$firstPassOut}, Cached:0) — retrying\n",
                FILE_APPEND);
            $ch2 = curl_init($url);
            curl_setopt($ch2, CURLOPT_POST,           true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS,     json_encode($payload));
            curl_setopt($ch2, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT,        120);
            $retryBody  = curl_exec($ch2);
            $retryErrno = curl_errno($ch2);
            $retryInfo  = curl_getinfo($ch2);
            curl_close($ch2);
            if (!$retryErrno && $retryInfo['http_code'] === 200) {
                $rawBody = $retryBody;
            }
        }
    }

    // ── Parse SSE lines and re-emit ───────────────────────────────────────────
    $usageMeta = null;
    $lines     = preg_split('/\r?\n/', $rawBody);

    foreach ($lines as $line) {
        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $jsonStr = substr($line, 6);
        $parsed  = json_decode($jsonStr, true);
        if ($parsed === null) continue;

        if (isset($parsed['usageMetadata'])) {
            $incoming = $parsed['usageMetadata'];
            if ($usageMeta === null || isset($incoming['cachedContentTokenCount'])) {
                $usageMeta = $incoming;
            }
        }

        $parts     = $parsed['candidates'][0]['content']['parts'] ?? [];
        $textDelta = null;
        foreach ($parts as $part) {
            if (isset($part['thoughtSignature'])) continue;
            $t = $part['text'] ?? null;
            if ($t !== null && $t !== '') { $textDelta = $t; break; }
        }
        if ($textDelta !== null) {
            echo "data: " . json_encode(['text' => $textDelta]) . "\n\n";
        }
    }

    $cachedTok = (int)($usageMeta['cachedContentTokenCount'] ?? 0);
    echo "data: " . json_encode(['meta' => [
        'cachedTokens' => $cachedTok,
        'inTokens'     => (int)($usageMeta['promptTokenCount']     ?? 0),
        'outTokens'    => (int)($usageMeta['candidatesTokenCount'] ?? 0),
    ]]) . "\n\n";

    echo "data: [DONE]\n\n";
    flush();

    // ── Usage logging ─────────────────────────────────────────────────────────
    $sessionId    = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
    $inTokens     = $usageMeta['promptTokenCount']        ?? 0;
    $outTokens    = $usageMeta['candidatesTokenCount']    ?? 0;
    $cachedTokens = $usageMeta['cachedContentTokenCount'] ?? 0;
    $cacheType    = $explicitCacheName !== null ? 'EXPLICIT_CACHE' : ($fileUri ? 'FILE_URI' : 'NO_FILE');
    $cacheFlag    = $cachedTokens > 0 ? "CACHE_HIT:{$cachedTokens}" : "CACHE_MISS";
    $logLine      = date('Y-m-d H:i:s') . " | $sessionId | $actualModel | In:$inTokens | Out:$outTokens | Cached:$cachedTokens | $cacheType | $cacheFlag\n";
    file_put_contents(__DIR__ . '/gemini_usage.log', $logLine, FILE_APPEND);
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Stream early-exit ─────────────────────────────────────────────────────────
if (isset($_GET['stream']) && $_GET['stream'] === '1') {
    handle_stream($data, $secretsFile, $cacheNameFile);
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
        file_put_contents(__DIR__ . '/gemini_usage.log', $ttfbLog, FILE_APPEND);
    }

    echo json_encode(['success' => true]);
    exit;
}

// --- CHAT ROUTE (non-streaming fallback) ---
if (!file_exists($secretsFile)) send_error('API Key file missing.');
require_once($secretsFile);

$modelMap = [
    'gemini-2.5-flash-lite' => 'gemini-2.5-flash-lite',
    'gemini-2.5-flash'      => 'gemini-2.5-flash',
];
$requested   = $data['model'] ?? 'gemini-2.5-flash-lite';
$actualModel = $modelMap[$requested] ?? 'gemini-2.5-flash-lite';

$fileUri      = null;
$fileMimeType = 'application/pdf';
if (file_exists($cacheNameFile)) {
    $saved = trim(file_get_contents($cacheNameFile));
    if (!empty($saved) && strpos($saved, 'generativelanguage.googleapis.com') !== false) {
        $expiryFile = $cacheNameFile . '.expires';
        $expired = true;
        if (file_exists($expiryFile)) {
            $expireTime = (int)trim(file_get_contents($expiryFile));
            $expired = (time() > $expireTime);
        }
        if (!$expired) $fileUri = $saved;
    }
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/$actualModel:generateContent?key=" . trim($GEMINI_API_KEY);

$contents = [];
if (isset($data['messages'])) {
    foreach ($data['messages'] as $m) {
        $role = ($m['role'] === 'assistant' || $m['role'] === 'model') ? 'model' : 'user';
        $parts = [];
        if (is_array($m['parts'])) {
            foreach ($m['parts'] as $p) {
                if (isset($p['text'])) $parts[] = ['text' => $p['text']];
            }
        } elseif (isset($m['content']) && is_string($m['content'])) {
            $parts[] = ['text' => $m['content']];
        }
        if (!empty($parts)) $contents[] = ['role' => $role, 'parts' => $parts];
    }
}

if (strlen(json_encode($contents)) > MAX_HISTORY_CHARS) {
    send_error('History too large. Please refresh and start a new session.');
}

$explicitCacheName = null;
$explicitModels    = json_decode(EXPLICIT_CACHE_MODELS_JSON, true);
if ($fileUri !== null && in_array($actualModel, $explicitModels)) {
    $explicitCacheName = get_or_create_explicit_cache(
        $actualModel, $fileUri, $fileMimeType, trim($GEMINI_API_KEY), $accountRoot,
        LOTF_SYSTEM_PROMPT
    );
}

$systemInstruction = ['parts' => [['text' => LOTF_SYSTEM_PROMPT]]];
$generationConfig  = ['maxOutputTokens' => 3000];

if ($explicitCacheName !== null) {
    $payload = [
        'cachedContent'    => $explicitCacheName,
        'contents'         => $contents,
        'generationConfig' => $generationConfig,
    ];
} else {
    if ($fileUri !== null) {
        array_unshift($contents, [
            'role'  => 'user',
            'parts' => [['file_data' => ['mime_type' => $fileMimeType, 'file_uri' => $fileUri]]]
        ]);
    }
    $payload = [
        'contents'          => $contents,
        'systemInstruction' => $systemInstruction,
        'generationConfig'  => $generationConfig,
    ];
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$responseData = json_decode($response, true);
$usage        = $responseData['usageMetadata'] ?? [];
$sessionId    = preg_replace('/[^a-z0-9_]/i', '_', $data['session_id'] ?? 'unknown');
$inTokens     = $usage['promptTokenCount']        ?? 0;
$outTokens    = $usage['candidatesTokenCount']    ?? 0;
$cachedTokens = $usage['cachedContentTokenCount'] ?? 0;
$cacheType    = $explicitCacheName !== null ? 'EXPLICIT_CACHE' : ($fileUri ? 'FILE_URI' : 'NO_FILE');
$cacheFlag    = $cachedTokens > 0 ? "CACHE_HIT:{$cachedTokens}" : "CACHE_MISS";
$logLine      = date('Y-m-d H:i:s') . " | $sessionId | $actualModel | In:$inTokens | Out:$outTokens | Cached:$cachedTokens | $cacheType | $cacheFlag\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;

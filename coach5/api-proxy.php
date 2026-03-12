<?php
/**
 * OHS Gemini Proxy - Updated for Student Logging, Stable Models, Files API, and SSE Streaming
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$studentFile   = $accountRoot . '/.secrets/student_roster.csv';
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// TUNABLE COST CONTROLS  — adjust here, mirrored in index.html
// ══════════════════════════════════════════════════════════════
//
// MAX_HISTORY_CHARS: hard ceiling on the serialised messages array
// (JSON bytes).  Rejects oversized payloads before any API call.
//
// Rule of thumb:  ~4 chars per Gemini token for English text.
// Images in HISTORY are base64 — 1 MB image ≈ 1.33 MB of chars,
// so a single uploaded photo can easily be 500k–1.3M chars alone.
// Images are NOT stored in HISTORY (they're sent once then dropped),
// so this limit applies only to text conversation history.
//
//  40,000 chars ≈  10,000 tokens  — default, short sessions
//  80,000 chars ≈  20,000 tokens  — allows ~3-4 pages of journal paste
// 160,000 chars ≈  40,000 tokens  — large journal dumps, higher cost
//
// Keep in sync with MAX_HISTORY_CHARS in index.html.
define('MAX_HISTORY_CHARS', 40_000);

// --- STREAMING HANDLER ---
function handle_stream($data, $secretsFile, $cacheNameFile) {

    // SSE headers — must be sent before any output
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');      // Prevent nginx from buffering
    header('Content-Encoding: identity'); // Ask mod_deflate not to compress
    header('Connection: keep-alive');
    // Belt-and-suspenders: set the Apache env var that mod_deflate checks
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }

    // Drain PHP output buffers so chunks flush immediately
    while (ob_get_level()) {
        ob_end_flush();
    }

    if (!file_exists($secretsFile)) {
        echo "data: " . json_encode(['error' => 'API Key file missing']) . "\n\n";
        flush();
        return;
    }
    require_once($secretsFile); // defines $GEMINI_API_KEY

    $modelMap = [
        "gemini-2.5-flash"      => "gemini-2.5-flash",
        "gemini-2.5-flash-lite" => "gemini-2.5-flash-lite",
        "gemini-2.5-pro"        => "gemini-2.5-pro",
        "gemini-3-flash-preview"=> "gemini-3-flash-preview",
        "gemini-3-pro-preview"  => "gemini-3-pro-preview",
        "gemini-2.0-flash"      => "gemini-2.0-flash",
        "gemini-2.0-flash-lite" => "gemini-2.0-flash-lite",
    ];
    $requested   = $data['model'] ?? 'gemini-2.5-flash';
    $actualModel = $modelMap[$requested] ?? "gemini-2.5-flash";

    // Streaming endpoint
    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . $actualModel
         . ":streamGenerateContent?alt=sse&key=" . trim($GEMINI_API_KEY);

    // ── Files API URI lookup (identical to non-streaming route) ──────────────
    $fileUri      = null;
    $fileMimeType = 'text/plain';
    $mimeHintFile = __DIR__ . '/Pasco-Municipal-Code-clean.mime';
    if (file_exists($mimeHintFile)) {
        $fileMimeType = trim(file_get_contents($mimeHintFile)) ?: 'text/plain';
    }
    if (file_exists($cacheNameFile)) {
        $saved = trim(file_get_contents($cacheNameFile));
        if (!empty($saved) && strpos($saved, 'generativelanguage.googleapis.com') !== false) {
            // Check expiry: Files API URIs last 48 hours; skip if stale
            $expiryFile = $cacheNameFile . '.expires';
            $expired = true;
            if (file_exists($expiryFile)) {
                $expireTime = (int)trim(file_get_contents($expiryFile));
                $expired = (time() > $expireTime);
            }
            if (!$expired) {
                $fileUri = $saved;
            }
            // If no expiry file exists, assume stale — don't risk a 400 error
        }
    }

    // ── Build contents array (identical to non-streaming route) ──────────────
    $contents = [];
    if (isset($data['messages'])) {
        foreach ($data['messages'] as $m) {
            $role  = ($m['role'] === 'assistant' || $m['role'] === 'model') ? 'model' : 'user';
            $parts = [];
            if (is_array($m['parts'])) {
                foreach ($m['parts'] as $p) {
                    if (isset($p['text']))       $parts[] = ["text"       => $p['text']];
                    if (isset($p['inline_data'])) $parts[] = ["inlineData" => $p['inline_data']];
                }
            } elseif (isset($m['content']) && is_string($m['content'])) {
                $parts[] = ["text" => $m['content']];
            }
            if (!empty($parts)) {
                $contents[] = ["role" => $role, "parts" => $parts];
            }
        }
    }

    // ── Server-side payload size guard ───────────────────────────────────────
    // Belt-and-suspenders check — client enforces MAX_HISTORY_CHARS too, but a
    // crafty student could POST directly. Abort with an SSE error event.
    if (strlen(json_encode($contents)) > MAX_HISTORY_CHARS) {
        echo "data: " . json_encode(['error' => 'History too large. Please refresh and start a new session.']) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        return;
    }

    // ── Prepend file URI as first user turn ───────────────────────────────────
    if ($fileUri !== null) {
        array_unshift($contents, [
            'role'  => 'user',
            'parts' => [[ 'file_data' => [ 'mime_type' => $fileMimeType, 'file_uri' => $fileUri ] ]]
        ]);
    }

    $payload = [
        "contents"          => $contents,
        "systemInstruction" => ["parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]],
        // Hard cap on output tokens — keeps responses concise and reduces latency.
        // 2000 tokens gives thinking models (e.g. Gemini 3 Pro) room to reason
        // AND produce a complete visible response. The system prompt instructs
        // ≤500 words so this is a safety net rather than a primary limiter.
        "generationConfig"  => ["maxOutputTokens" => 2000]
    ];

    // ── Fetch the full SSE response with RETURNTRANSFER ──────────────────────
    // CURLOPT_WRITEFUNCTION closures are silently ignored on some shared hosts
    // (Bluehost / cPanel PHP) regardless of RETURNTRANSFER=false.  The safe
    // alternative is to collect the full body with RETURNTRANSFER=true, parse
    // the SSE lines ourselves, then stream them to the browser in one pass.
    // True per-chunk streaming is lost, but the browser still receives the
    // full text and metadata in one SSE burst which is indistinguishable to
    // the ReadableStream parser in index.html.
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
    // Flash-Lite (and some other models) return Out:1 on the very first request
    // after the implicit KV cache has expired (~5-10 min inactivity), especially
    // with a large context like the 1M-token municipal code file.  The first call
    // still warms the cache, so an immediate retry almost always gets a CACHE_HIT
    // and returns a real response.  Guard: only retry when a file was in the
    // payload (large context is the trigger) and output was suspiciously tiny.
    if ($fileUri !== null) {
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
            // Only swap in the retry body if it actually succeeded
            if (!$retryErrno && $retryInfo['http_code'] === 200) {
                $rawBody = $retryBody;
            }
        }
    }

    // ── Parse SSE lines, re-emit text deltas, collect usageMetadata ──────────
    $usageMeta = null;
    $lines     = preg_split('/\r?\n/', $rawBody);

    foreach ($lines as $line) {
        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $jsonStr = substr($line, 6);
        $parsed  = json_decode($jsonStr, true);
        if ($parsed === null) continue;

        // Capture usage — prefer the chunk that has cachedContentTokenCount
        if (isset($parsed['usageMetadata'])) {
            $incoming = $parsed['usageMetadata'];
            if ($usageMeta === null || isset($incoming['cachedContentTokenCount'])) {
                $usageMeta = $incoming;
            }
        }

        // Forward text delta — skip thinking-model parts (thoughtSignature)
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

    // ── Emit meta event (cache hit info for the badge in index.html) ─────────
    $cachedTok = (int)($usageMeta['cachedContentTokenCount'] ?? 0);
    echo "data: " . json_encode(['meta' => [
        'cachedTokens' => $cachedTok,
        'inTokens'     => (int)($usageMeta['promptTokenCount']     ?? 0),
        'outTokens'    => (int)($usageMeta['candidatesTokenCount'] ?? 0),
    ]]) . "\n\n";

    // Send done sentinel
    echo "data: [DONE]\n\n";
    flush();

    // ── Usage logging (same format as non-streaming route) ───────────────────
    $studentName   = $data['student_name'] ?? 'Unknown';
    $studentID     = $data['student_id']   ?? 'unknown';
    $fileFlag      = $fileUri ? "FILE_URI" : "NO_FILE";
    $inTokens      = $usageMeta['promptTokenCount']        ?? 0;
    $outTokens     = $usageMeta['candidatesTokenCount']    ?? 0;
    $cachedTokens  = $usageMeta['cachedContentTokenCount'] ?? 0;
    $cacheFlag     = $cachedTokens > 0 ? "CACHE_HIT:{$cachedTokens}" : "CACHE_MISS";
    $logLine       = date('Y-m-d H:i:s') . " | $studentName | $actualModel | In:$inTokens | Out:$outTokens | Cached:$cachedTokens | $fileFlag | $cacheFlag | ID:$studentID\n";
    file_put_contents(__DIR__ . '/gemini_usage.log', $logLine, FILE_APPEND);
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Stream early-exit (before JSON Content-Type is set) ───────────────────────
if (isset($_GET['stream']) && $_GET['stream'] === '1') {
    handle_stream($data, $secretsFile, $cacheNameFile);
    exit;
}

// ── Non-streaming routes ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// --- CRON STATUS ROUTE ---
// Usage: POST {"action":"cron_status","secret":"amentum2025"}
if (isset($data['action']) && $data['action'] === 'cron_status') {
    if (($data['secret'] ?? '') !== 'amentum2025') { send_error('Forbidden'); }

    $expiryFile  = $cacheNameFile . '.expires';
    $pingLog     = __DIR__ . '/cache-ping.log';

    // File URI status
    $uriStatus = ['uri' => null, 'expired' => true, 'expires_at' => null, 'expires_human' => 'No expiry file'];
    if (file_exists($cacheNameFile)) {
        $uriStatus['uri'] = trim(file_get_contents($cacheNameFile));
    }
    if (file_exists($expiryFile)) {
        $expireTime = (int)trim(file_get_contents($expiryFile));
        $uriStatus['expires_at']    = $expireTime;
        $uriStatus['expires_human'] = date('Y-m-d H:i:s T', $expireTime);
        $uriStatus['expired']       = (time() > $expireTime);
        $uriStatus['hours_left']    = round(($expireTime - time()) / 3600, 1);
    }

    // Last N lines of cache-ping.log
    $logLines = [];
    if (file_exists($pingLog)) {
        $lines = file($pingLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logLines = array_slice($lines, -30); // last 30 lines
    }

    echo json_encode([
        'now'         => date('Y-m-d H:i:s T'),
        'file_cache'  => $uriStatus,
        'ping_log'    => $logLines,
        'log_exists'  => file_exists($pingLog),
        'log_size'    => file_exists($pingLog) ? filesize($pingLog) : 0,
    ], JSON_PRETTY_PRINT);
    exit;
}

// --- DEBUG ROUTE (remove after diagnosis) ---
// Usage: POST {"action":"debug_chat","secret":"amentum2025","model":"gemini-2.5-flash","message":"Hello"}
if (isset($data['action']) && $data['action'] === 'debug_chat') {
    if (($data['secret'] ?? '') !== 'amentum2025') { send_error('Forbidden'); }
    if (!file_exists($secretsFile)) send_error("API Key file missing.");
    require_once($secretsFile);
    $model = $data['model'] ?? 'gemini-2.5-flash';
    $msg   = $data['message'] ?? 'Say hello';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key=" . trim($GEMINI_API_KEY);
    $payload = [
        "contents" => [["role" => "user", "parts" => [["text" => $msg]]]],
        "systemInstruction" => ["parts" => [["text" => "You are a helpful assistant."]]]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    $rawBody   = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode([
        'http_code'  => $httpCode,
        'curl_errno' => $curlErrno,
        'curl_error' => $curlError,
        'url_used'   => $url,
        'raw_body'   => $rawBody,   // first 4000 chars
    ], JSON_PRETTY_PRINT);
    exit;
}

// --- 0. GET MODELS ROUTE ---
// Serves the cached model list saved by list-models.php?save=1
if (isset($data['action']) && $data['action'] === 'get_models') {
    $cacheFile = __DIR__ . '/models_cache.json';
    if (!file_exists($cacheFile)) {
        send_error("Model cache not found. Visit list-models.php?secret=amentum2025&save=1 to generate it.");
    }
    $models = json_decode(file_get_contents($cacheFile), true);
    if (!$models) {
        send_error("Model cache is invalid or empty.");
    }
    echo json_encode(['success' => true, 'models' => $models, 'cached_at' => filemtime($cacheFile)]);
    exit;
}

// --- 1. LOGIN ROUTE ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    if (!file_exists($studentFile)) send_error("Roster file missing.");
    $handle = fopen($studentFile, "r");
    fgetcsv($handle);
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (trim($row[2]) == trim($data['student_id'] ?? '') && trim($row[6]) == trim($data['password'] ?? '')) {
            echo json_encode(['success' => true, 'student_name' => $row[9]]);
            fclose($handle); exit;
        }
    }
    fclose($handle);
    send_error("Invalid credentials.");
}

// --- 2a. STUDENT IMAGE LOGGING (moderation archive) ---
// Saves uploaded images to student_logs/images/ for teacher review.
// Directory is blocked from direct web access via .htaccess written on first use.
if (isset($data['action']) && $data['action'] === 'log_image') {
    $studentId   = preg_replace('/[^a-z0-9_]/i', '_', $data['student_id']   ?? 'unknown');
    $studentName = $data['student_name'] ?? 'Unknown';
    $context     = substr($data['context'] ?? '', 0, 200); // cap for log line safety
    $images      = $data['images'] ?? [];

    $imageDir = __DIR__ . '/student_logs/images';
    if (!is_dir($imageDir)) { mkdir($imageDir, 0755, true); }

    // Block direct browser access to the image archive
    $htaccess = $imageDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',  'image/jpg'  => 'jpg',
        'image/png'  => 'png',  'image/gif'  => 'gif',
        'image/webp' => 'webp', 'image/heic' => 'heic',
        'image/heif' => 'heif', 'image/bmp'  => 'bmp',
    ];

    $timestamp = date('Ymd_His');
    $saved = [];
    foreach ($images as $i => $img) {
        $mimeType = $img['mime_type'] ?? 'image/jpeg';
        $ext      = $mimeToExt[$mimeType] ?? 'jpg';
        $b64      = $img['data'] ?? '';
        if (empty($b64)) continue;
        $decoded = base64_decode($b64, true);
        if ($decoded === false) continue;
        $filename = "{$studentId}_{$timestamp}_{$i}.{$ext}";
        file_put_contents($imageDir . '/' . $filename, $decoded);
        $saved[] = $filename;
    }

    // Append a line to the usage log so you have a searchable audit trail
    if (!empty($saved)) {
        $logLine = date('Y-m-d H:i:s') . " | $studentName | IMAGE_SAVED | "
                 . implode(',', $saved) . " | Context: $context\n";
        file_put_contents(__DIR__ . '/gemini_usage.log', $logLine, FILE_APPEND);
    }

    echo json_encode(['success' => true, 'saved' => $saved]);
    exit;
}

// --- 2. STUDENT INTERACTION LOGGING ---
if (isset($data['action']) && $data['action'] === 'log_interaction') {
    $studentId = $data['student_id'] ?? 'unknown_student';
    $logContent = $data['log'] ?? '';

    if (!is_dir('student_logs')) { mkdir('student_logs', 0777, true); }
    $logFilename = "student_logs/" . preg_replace('/[^a-z0-9]/i', '_', $studentId) . ".txt";

    $timestamp = date('Y-m-d H:i:s');
    $formattedLog = "--- Entry: $timestamp ---\n" . $logContent . "\n";

    file_put_contents($logFilename, $formattedLog, FILE_APPEND);

    // Append TTFB to usage log if provided (enables cache-hit analysis)
    if (isset($data['ttfb_ms']) && is_numeric($data['ttfb_ms'])) {
        $ttfbMs  = (int)$data['ttfb_ms'];
        $ttfbLog = date('Y-m-d H:i:s') . " | $studentId | TTFB:{$ttfbMs}ms\n";
        file_put_contents(__DIR__ . '/gemini_usage.log', $ttfbLog, FILE_APPEND);
    }

    echo json_encode(['success' => true]);
    exit;
}

// --- 3. CHAT ROUTE (non-streaming fallback) ---
if (!file_exists($secretsFile)) send_error("API Key file missing.");
require_once($secretsFile);

$modelMap = [
    "gemini-2.5-flash"      => "gemini-2.5-flash",
    "gemini-2.5-flash-lite" => "gemini-2.5-flash-lite",
    "gemini-2.5-pro"        => "gemini-2.5-pro",
    "gemini-3-flash-preview"=> "gemini-3-flash-preview",
    "gemini-3-pro-preview"  => "gemini-3-pro-preview",
    "gemini-2.0-flash"      => "gemini-2.0-flash",
    "gemini-2.0-flash-lite" => "gemini-2.0-flash-lite",
];

$requested   = $data['model'] ?? 'gemini-2.5-flash';
$actualModel = $modelMap[$requested] ?? "gemini-2.5-flash";

// ── Files API URI lookup ──────────────────────────────────────────────────────
$fileUri      = null;
$fileMimeType = 'text/plain'; // default; overridden by hint file if present
$mimeHintFile = __DIR__ . '/Pasco-Municipal-Code-clean.mime';
if (file_exists($mimeHintFile)) {
    $fileMimeType = trim(file_get_contents($mimeHintFile)) ?: 'text/plain';
}
if (file_exists($cacheNameFile)) {
    $saved = trim(file_get_contents($cacheNameFile));
    if (!empty($saved) && strpos($saved, 'generativelanguage.googleapis.com') !== false) {
        // Check expiry: Files API URIs last 48 hours; skip if stale
        $expiryFile = $cacheNameFile . '.expires';
        $expired = true;
        if (file_exists($expiryFile)) {
            $expireTime = (int)trim(file_get_contents($expiryFile));
            $expired = (time() > $expireTime);
        }
        if (!$expired) {
            $fileUri = $saved;
        }
        // If no expiry file exists, assume stale — don't risk a 400 error
    }
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/$actualModel:generateContent?key=" . trim($GEMINI_API_KEY);

$contents = [];
if (isset($data['messages'])) {
    foreach ($data['messages'] as $m) {
        // Handle both 'model' and 'assistant' keys for flexibility
        $role = ($m['role'] === 'assistant' || $m['role'] === 'model') ? 'model' : 'user';
        $parts = [];

        // Handle array-based parts from the new index.html
        if (is_array($m['parts'])) {
            foreach ($m['parts'] as $p) {
                if (isset($p['text'])) {
                    $parts[] = ["text" => $p['text']];
                }
                if (isset($p['inline_data'])) {
                    $parts[] = ["inlineData" => $p['inline_data']];
                }
            }
        }
        // Backward compatibility for string-based content
        elseif (isset($m['content']) && is_string($m['content'])) {
            $parts[] = ["text" => $m['content']];
        }

        if (!empty($parts)) {
            $contents[] = ["role" => $role, "parts" => $parts];
        }
    }
}

// ── Server-side payload size guard (non-streaming route) ─────────────────────
if (strlen(json_encode($contents)) > MAX_HISTORY_CHARS) {
    send_error('History too large. Please refresh and start a new session.');
}

// ── Prepend the municipal code file as the first user turn ───────────────────
if ($fileUri !== null) {
    array_unshift($contents, [
        'role'  => 'user',
        'parts' => [[
            'file_data' => [
                'mime_type' => $fileMimeType,
                'file_uri'  => $fileUri
            ]
        ]]
    ]);
}

$payload = [
    "contents"          => $contents,
    "systemInstruction" => ["parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]],
    // Hard cap on output tokens — same as streaming route for consistency.
    "generationConfig"  => ["maxOutputTokens" => 2000]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 4. USAGE LOGGING (Teacher Dashboard Data) ---
$responseData = json_decode($response, true);
$usage = $responseData['usageMetadata'] ?? [];
$studentName  = $data['student_name'] ?? 'Unknown';
$studentID    = $data['student_id']   ?? 'unknown';
$fileFlag     = $fileUri ? "FILE_URI" : "NO_FILE";
$inTokens     = $usage['promptTokenCount']        ?? 0;
$outTokens    = $usage['candidatesTokenCount']    ?? 0;
$cachedTokens = $usage['cachedContentTokenCount'] ?? 0;
$cacheFlag    = $cachedTokens > 0 ? "CACHE_HIT:{$cachedTokens}" : "CACHE_MISS";
$logLine      = date('Y-m-d H:i:s') . " | $studentName | $actualModel | In:$inTokens | Out:$outTokens | Cached:$cachedTokens | $fileFlag | $cacheFlag | ID:$studentID\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;

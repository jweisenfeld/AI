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
        "gemini-3-pro-preview"  => "gemini-3-pro-preview",
        "gemini-2.5-flash"      => "gemini-2.5-flash",
        "gemini-2.5-flash-lite" => "gemini-2.5-flash-lite"
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
            $fileUri = $saved;
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
        "systemInstruction" => ["parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]]
    ];

    // ── State captured by the CURLOPT_WRITEFUNCTION closure ──────────────────
    $buffer      = '';   // Accumulates partial lines across curl chunks
    $usageMeta   = null; // Populated when the final chunk with usageMetadata arrives
    $metaSent    = false; // Guard: emit meta event only once
    $rawLog      = '';   // DEBUG: captures full raw response for inspection

    $writeCallback = function($ch, $chunk) use (&$buffer, &$usageMeta, &$metaSent, &$rawLog) {
        $rawLog .= $chunk; // DEBUG
        $buffer .= $chunk;

        // Process all complete lines in the buffer
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line   = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $line   = rtrim($line, "\r"); // handle CRLF

            if (strncmp($line, 'data: ', 6) !== 0) continue;

            $jsonStr = substr($line, 6);
            $parsed  = json_decode($jsonStr, true);
            if ($parsed === null) continue;

            // Capture usage — prefer chunks that include cachedContentTokenCount
            // (intermediate chunks often have usageMetadata but without cached field)
            if (isset($parsed['usageMetadata'])) {
                $incoming = $parsed['usageMetadata'];
                // Always keep the most complete version: prefer one with cachedContentTokenCount
                if ($usageMeta === null || isset($incoming['cachedContentTokenCount'])) {
                    $usageMeta = $incoming;
                }
                // Emit meta event as soon as we have cachedContentTokenCount
                // (it arrives in the final usageMetadata chunk from Gemini)
                if (!$metaSent && isset($incoming['cachedContentTokenCount'])) {
                    $metaSent = true;
                    echo "data: " . json_encode(['meta' => [
                        'cachedTokens' => (int)($incoming['cachedContentTokenCount'] ?? 0),
                        'inTokens'     => (int)($incoming['promptTokenCount']        ?? 0),
                        'outTokens'    => (int)($incoming['candidatesTokenCount']    ?? 0),
                    ]]) . "\n\n";
                    flush();
                }
            }

            // Forward text delta to client — skip thinking-model parts (thoughtSignature)
            $parts = $parsed['candidates'][0]['content']['parts'] ?? [];
            $textDelta = null;
            foreach ($parts as $part) {
                if (isset($part['thoughtSignature'])) continue; // thinking artifact
                $t = $part['text'] ?? null;
                if ($t !== null && $t !== '') { $textDelta = $t; break; }
            }
            if ($textDelta !== null) {
                echo "data: " . json_encode(['text' => $textDelta]) . "\n\n";
                flush();
            }
        }

        return strlen($chunk); // MUST return byte count or curl aborts
    };

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION,  $writeCallback);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Must be false with WRITEFUNCTION
    $curlResult = curl_exec($ch);
    $curlErrno  = curl_errno($ch);
    $curlError  = curl_error($ch);
    $curlInfo   = curl_getinfo($ch);
    curl_close($ch);

    // DEBUG: log curl diagnostics + raw tail to pinpoint why usageMeta is null
    file_put_contents(__DIR__ . '/gemini_debug.log',
        date('Y-m-d H:i:s') . "\n" .
        "  curl_exec result : " . var_export($curlResult, true) . "\n" .
        "  curl_errno       : $curlErrno\n" .
        "  curl_error       : $curlError\n" .
        "  http_code        : " . ($curlInfo['http_code'] ?? 'n/a') . "\n" .
        "  total_time       : " . ($curlInfo['total_time'] ?? 'n/a') . "s\n" .
        "  size_download    : " . ($curlInfo['size_download'] ?? 'n/a') . " bytes\n" .
        "  url              : " . ($curlInfo['url'] ?? 'n/a') . "\n" .
        "  rawLog bytes     : " . strlen($rawLog) . "\n" .
        "  usageMeta        : " . json_encode($usageMeta) . "\n" .
        "  RAW_TAIL         :\n" . substr($rawLog, -1000) . "\n" .
        str_repeat('-', 60) . "\n",
        FILE_APPEND);

    // ── Fallback: emit meta event if cachedContentTokenCount never arrived
    // (e.g. cache miss — usageMetadata present but field absent)
    if (!$metaSent) {
        echo "data: " . json_encode(['meta' => [
            'cachedTokens' => 0,
            'inTokens'     => (int)($usageMeta['promptTokenCount']     ?? 0),
            'outTokens'    => (int)($usageMeta['candidatesTokenCount'] ?? 0),
        ]]) . "\n\n";
        flush();
    }

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
    "gemini-3-pro-preview"  => "gemini-3-pro-preview",
    "gemini-2.5-flash"      => "gemini-2.5-flash",
    "gemini-2.5-flash-lite" => "gemini-2.5-flash-lite"
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
        $fileUri = $saved;
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
    "systemInstruction" => ["parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]]
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

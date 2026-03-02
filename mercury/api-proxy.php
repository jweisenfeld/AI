<?php
/**
 * Mercury 2 API Proxy
 * Pasco School District — Physics AI Tools
 *
 * Routes requests to InceptionLabs Mercury 2 (OpenAI-compatible API).
 * - Rate limiting: 30 req/hour, 150 req/day per student
 * - School-hours throttling (Mon–Fri 7 AM–5 PM Pacific)
 * - Conversation length cap (50 messages)
 * - Input size cap (200 KB)
 * - Usage logging to mercury_usage.log
 *
 * Secrets file: $accountRoot/.secrets/inceptionkey.php
 *   return ['INCEPTION_API_KEY' => 'sk_...'];
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Load API key ──────────────────────────────────────────────
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/inceptionkey.php';

if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Mercury proxy: secrets not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$INCEPTION_API_KEY = $secrets['INCEPTION_API_KEY'] ?? null;

if (!$INCEPTION_API_KEY) {
    http_response_code(500);
    error_log("Mercury proxy: INCEPTION_API_KEY missing in $secretsFile");
    echo json_encode(['error' => 'Server configuration error (API key missing).']);
    exit;
}

// ── Parse request ─────────────────────────────────────────────
$raw         = file_get_contents('php://input');
$requestData = json_decode($raw, true);

if (!is_array($requestData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON in request body.']);
    exit;
}

if (!isset($requestData['messages']) || !is_array($requestData['messages']) || empty($requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or empty messages array.']);
    exit;
}

// ── Student ID ────────────────────────────────────────────────
$studentId = isset($requestData['student_id']) && is_string($requestData['student_id'])
    ? substr(trim($requestData['student_id']), 0, 50)
    : 'unknown';

// ── Rate limiting (per-student, file-based) ───────────────────
$rateLimitDir = __DIR__ . '/rate_limits';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

$RATE_LIMIT_PER_HOUR = 30;
$RATE_LIMIT_PER_DAY  = 150;

if ($studentId !== 'unknown') {
    $rlFile = $rateLimitDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId) . '.json';
    $now    = time();
    $rlData = [];

    if (is_readable($rlFile)) {
        $rlData = json_decode(file_get_contents($rlFile), true) ?: [];
    }

    // Prune timestamps older than 24 hours
    $rlData['requests'] = array_values(array_filter(
        $rlData['requests'] ?? [],
        function ($ts) use ($now) { return ($now - $ts) < 86400; }
    ));

    $lastHour = array_filter($rlData['requests'], function ($ts) use ($now) {
        return ($now - $ts) < 3600;
    });

    if (count($lastHour) >= $RATE_LIMIT_PER_HOUR) {
        $resetIn = min(array_map(function ($ts) use ($now) { return 3600 - ($now - $ts); }, $lastHour));
        http_response_code(429);
        echo json_encode(['error' => "Rate limit: {$RATE_LIMIT_PER_HOUR} requests/hour. Retry in " . ceil($resetIn / 60) . " min."]);
        exit;
    }

    if (count($rlData['requests']) >= $RATE_LIMIT_PER_DAY) {
        http_response_code(429);
        echo json_encode(['error' => "Daily limit: {$RATE_LIMIT_PER_DAY} requests/day. Try again tomorrow."]);
        exit;
    }

    $rlData['requests'][] = $now;
    file_put_contents($rlFile, json_encode($rlData), LOCK_EX);
}

// ── School hours (Pacific) ────────────────────────────────────
$tz          = new DateTimeZone('America/Los_Angeles');
$nowLocal    = new DateTime('now', $tz);
$hour        = (int)$nowLocal->format('G');
$dow         = (int)$nowLocal->format('N');  // 1=Mon, 7=Sun
$isSchoolHrs = ($dow >= 1 && $dow <= 5 && $hour >= 7 && $hour < 17);

// ── Conversation length cap ───────────────────────────────────
$MAX_MESSAGES = 50;
$msgCount     = count($requestData['messages']);

if ($msgCount > $MAX_MESSAGES) {
    http_response_code(400);
    echo json_encode(['error' => "Conversation too long ({$msgCount} messages). Max is {$MAX_MESSAGES}. Clear chat to continue."]);
    exit;
}

// ── Input size cap ────────────────────────────────────────────
if (strlen($raw) > 200000) {
    http_response_code(400);
    echo json_encode(['error' => 'Request too large. Clear chat and start a shorter conversation.']);
    exit;
}

// ── Build API request (OpenAI chat/completions format) ────────
$messages = $requestData['messages'];

// Prepend system message if provided
if (!empty($requestData['system']) && is_string($requestData['system'])) {
    array_unshift($messages, [
        'role'    => 'system',
        'content' => trim($requestData['system']),
    ]);
}

$apiRequest = [
    'model'      => 'mercury-2',
    'messages'   => $messages,
    'max_tokens' => min((int)($requestData['max_tokens'] ?? 2048), 16384),
];

if (isset($requestData['temperature'])) {
    $temp = round((float)$requestData['temperature'], 2);
    if ($temp >= 0.0 && $temp <= 2.0) {
        $apiRequest['temperature'] = $temp;
    }
}

// ── Call InceptionLabs API ────────────────────────────────────
$ch = curl_init('https://api.inceptionlabs.ai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($apiRequest),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $INCEPTION_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 120,
]);

$apiResponse = curl_exec($ch);
$httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to InceptionLabs API: ' . $curlError]);
    exit;
}

// ── Parse response ────────────────────────────────────────────
$decoded = json_decode($apiResponse, true);
$reply   = null;

if (is_array($decoded)) {
    $reply = $decoded['choices'][0]['message']['content'] ?? null;
}

// ── Log usage ─────────────────────────────────────────────────
$logEntry = [
    'timestamp'    => date('Y-m-d H:i:s'),
    'student_id'   => $studentId,
    'model'        => 'mercury-2',
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'msg_count'    => $msgCount,
    'school_hours' => $isSchoolHrs,
    'http_status'  => $httpCode,
];

if (is_array($decoded) && isset($decoded['usage'])) {
    $logEntry['prompt_tokens']     = $decoded['usage']['prompt_tokens']     ?? 0;
    $logEntry['completion_tokens'] = $decoded['usage']['completion_tokens'] ?? 0;
}

file_put_contents(
    __DIR__ . '/mercury_usage.log',
    json_encode($logEntry) . "\n",
    FILE_APPEND | LOCK_EX
);

// ── Return response ───────────────────────────────────────────
if ($httpCode < 200 || $httpCode >= 300 || $reply === null) {
    http_response_code(max($httpCode, 500));
    $errMsg = isset($decoded['error']['message'])
        ? $decoded['error']['message']
        : $apiResponse;
    echo json_encode(['error' => $errMsg]);
    exit;
}

echo json_encode([
    'reply' => $reply,
    'usage' => $decoded['usage'] ?? null,
    'model' => 'mercury-2',
]);

// (No closing PHP tag is recommended)

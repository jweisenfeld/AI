<?php
/**
 * Emotion Wheel Coach - API Proxy
 * Pasco School District
 *
 * Haiku-only proxy for the emotion wheel SEL coaching tool.
 * - Same student login/roster as claude/
 * - Per-student rate limiting and logging
 * - System prompt injected server-side
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

// Secrets and roster live outside public_html (same files as claude/)
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsDir  = $accountRoot . '/.secrets';
$secretsFile = $secretsDir . '/claudekey.php';
$studentFile = $secretsDir . '/student_roster.csv';

if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("wheel3: Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;

if (!$ANTHROPIC_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error (API key missing).']);
    exit;
}

$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!is_array($requestData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON in request body']);
    exit;
}

// ============================================
// LOGIN ROUTE
// ============================================
if (($requestData['action'] ?? '') === 'verify_login') {
    if (!is_readable($studentFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Roster file missing or not readable.']);
        exit;
    }
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header
    $found = false;
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2], $row[6]) &&
            trim($row[2]) === trim($requestData['student_id'] ?? '') &&
            trim($row[6]) === trim($requestData['password'] ?? '')) {
            $found = true;
            $studentName = $row[9] ?? $row[2];
            break;
        }
    }
    fclose($handle);
    if ($found) {
        echo json_encode(['success' => true, 'student_name' => $studentName]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
    }
    exit;
}

// ============================================
// CHAT ROUTE — validate required fields
// ============================================
if (!isset($requestData['messages']) || !is_array($requestData['messages']) || empty($requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or empty messages array.']);
    exit;
}

$studentId = isset($requestData['student_id']) && is_string($requestData['student_id'])
    ? substr(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($requestData['student_id'])), 0, 50)
    : 'unknown';

// ============================================
// RATE LIMITING (per-student, file-based)
// ============================================
$rateLimitDir = __DIR__ . '/rate_limits';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

$RATE_LIMIT_PER_HOUR = 20;
$RATE_LIMIT_PER_DAY  = 60;

if ($studentId !== 'unknown') {
    $rateLimitFile = $rateLimitDir . '/' . $studentId . '.json';
    $now = time();
    $rateData = [];

    if (is_readable($rateLimitFile)) {
        $rateData = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }

    // Drop entries older than 24 hours
    $rateData['requests'] = array_values(array_filter(
        $rateData['requests'] ?? [],
        fn($ts) => ($now - $ts) < 86400
    ));

    $lastHour = array_filter($rateData['requests'], fn($ts) => ($now - $ts) < 3600);

    if (count($lastHour) >= $RATE_LIMIT_PER_HOUR) {
        $resetIn = min(array_map(fn($ts) => 3600 - ($now - $ts), $lastHour));
        http_response_code(429);
        echo json_encode(['error' => ['type' => 'rate_limit_error',
            'message' => "You've reached the hourly limit. Try again in " . ceil($resetIn / 60) . " minutes."]]);
        exit;
    }

    if (count($rateData['requests']) >= $RATE_LIMIT_PER_DAY) {
        http_response_code(429);
        echo json_encode(['error' => ['type' => 'rate_limit_error',
            'message' => 'Daily limit reached. Try again tomorrow.']]);
        exit;
    }

    $rateData['requests'][] = $now;
    file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);
}

// ============================================
// INPUT SIZE CAP
// ============================================
if (strlen($input) > 50000) {
    http_response_code(400);
    echo json_encode(['error' => 'Request too large. Please start a new conversation.']);
    exit;
}

// ============================================
// CONVERSATION LENGTH CAP
// ============================================
$MAX_MESSAGES = 20;
$messageCount = count($requestData['messages']);
if ($messageCount > $MAX_MESSAGES) {
    http_response_code(400);
    echo json_encode(['error' => ['type' => 'conversation_too_long',
        'message' => 'This conversation is getting long! Try restarting the wheel to explore a new feeling.']]);
    exit;
}

// ============================================
// BUILD SYSTEM PROMPT (server-side, never sent to client)
// ============================================
$langMap = ['en' => 'English', 'es' => 'Spanish', 'ru' => 'Russian'];
$lang = $requestData['language'] ?? 'en';
$langName = $langMap[$lang] ?? 'English';

$studentName = '';
if (isset($requestData['student_name']) && is_string($requestData['student_name'])) {
    $studentName = htmlspecialchars(substr(trim($requestData['student_name']), 0, 80), ENT_QUOTES, 'UTF-8');
}
$nameGreeting = $studentName ? "The student's name is {$studentName}." : '';

$systemPrompt = <<<PROMPT
You are a warm, practical emotional wellness coach for high school students. Students use this tool after identifying their emotion on an emotion wheel and reading coping strategies. {$nameGreeting}

Your goals:
- Validate the student's specific emotion — acknowledge it genuinely, without judgment
- Offer 1 to 2 concrete, actionable things they can do TODAY (be specific, not vague)
- Keep responses short: 3 to 5 sentences max. Conversational and real, never preachy
- Reference the specific coping strategies they were shown when it feels natural

In follow-up messages:
- Respond to exactly what the student shares
- Connect back to their specific emotion or the strategies shown
- When the moment feels right, gently invite them to revisit the emotion wheel — to go deeper into what they're feeling, or to work toward a more positive emotion
- Keep it engaging and human — like a trusted coach, not a textbook

IMPORTANT — Safety: If a student mentions wanting to hurt themselves or others, immediately and warmly encourage them to speak with a trusted adult or school counselor in person. Do not try to handle crisis situations yourself.

Always respond in {$langName}. If the student writes in a different language, match their language.
PROMPT;

// ============================================
// CALL ANTHROPIC API (Haiku only)
// ============================================

// Haiku model with fallbacks
$haikuModels = ['claude-haiku-4-5-20251001', 'claude-haiku-4-5', 'claude-3-haiku-20240307'];

$apiRequest = [
    'model'      => $haikuModels[0],
    'max_tokens' => 512,
    'system'     => $systemPrompt,
    'messages'   => $requestData['messages'],
];

list($httpCode, $response, $curlError) = callAnthropicApi($apiRequest, $ANTHROPIC_API_KEY);

// Auto-heal: try fallbacks if model is unavailable
if (!$curlError && $httpCode === 400) {
    $errData = json_decode($response, true);
    if (isModelError($httpCode, $errData)) {
        foreach (array_slice($haikuModels, 1) as $fallback) {
            $apiRequest['model'] = $fallback;
            list($httpCode, $response, $curlError) = callAnthropicApi($apiRequest, $ANTHROPIC_API_KEY);
            if ($curlError || !isModelError($httpCode, json_decode($response, true))) break;
        }
    }
}

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to API. Please try again.']);
    exit;
}

$responseData = json_decode($response, true);

// ============================================
// LOGGING
// ============================================
$responseText = '';
if (isset($responseData['content'])) {
    foreach ($responseData['content'] as $block) {
        if (($block['type'] ?? '') === 'text') $responseText .= $block['text'] ?? '';
    }
}

$lastUserMsg = '';
foreach (array_reverse($requestData['messages']) as $msg) {
    if (($msg['role'] ?? '') === 'user') {
        $lastUserMsg = is_string($msg['content']) ? $msg['content'] : '';
        break;
    }
}

$logEntry = [
    'timestamp'     => date('Y-m-d H:i:s'),
    'student_id'    => $studentId,
    'student_name'  => $studentName,
    'language'      => $lang,
    'model'         => $apiRequest['model'],
    'ip'            => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => $messageCount,
    'input_tokens'  => $responseData['usage']['input_tokens'] ?? 0,
    'output_tokens' => $responseData['usage']['output_tokens'] ?? 0,
    'http_status'   => $httpCode,
    'user_text'     => mb_substr($lastUserMsg, 0, 500),
];

// Usage log (one JSON line per request)
$usageLog = __DIR__ . '/wheel3_usage.log';
file_put_contents($usageLog, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Per-student log (full prompt + response)
writeStudentLog($studentId, $lastUserMsg, $responseText, $logEntry);

http_response_code($httpCode);
echo $response;

// ============================================
// HELPERS
// ============================================

function callAnthropicApi(array $apiRequest, string $apiKey): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($apiRequest),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError];
}

function isModelError(int $httpCode, ?array $data): bool
{
    if ($httpCode !== 400 || !is_array($data)) return false;
    return ($data['error']['type'] ?? '') === 'invalid_request_error'
        && strpos(strtolower($data['error']['message'] ?? ''), 'model') !== false;
}

function writeStudentLog(string $studentId, string $userText, string $responseText, array $meta): void
{
    $logDir = __DIR__ . '/student_logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $htaccess = $logDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $safeId  = preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId ?: 'unknown');
    $logFile = $logDir . '/' . $safeId . '.txt';

    $ts     = $meta['timestamp'] ?? date('Y-m-d H:i:s');
    $model  = $meta['model'] ?? 'unknown';
    $inTok  = $meta['input_tokens'] ?? 0;
    $outTok = $meta['output_tokens'] ?? 0;
    $lang   = $meta['language'] ?? '??';

    $entry  = "=== {$ts} | {$model} | {$lang} | In:{$inTok} Out:{$outTok} ===\n";
    $entry .= "USER:\n" . $userText . "\n\n";
    $entry .= "COACH:\n" . $responseText . "\n";
    $entry .= str_repeat('-', 60) . "\n\n";

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

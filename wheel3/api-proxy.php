<?php
/**
 * Emotion Wheel Coach - API Proxy
 * Pasco School District
 *
 * Haiku-only proxy for the emotion wheel SEL coaching tool.
 * - Same student login/roster as claude/
 * - Per-student rate limiting and logging
 * - System prompt injected server-side
 * - Safety alert emails via Gmail SMTP (same credentials as coach6)
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

// Secrets and roster live outside public_html (same files as claude/ and coach6)
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsDir  = $accountRoot . '/.secrets';
$secretsFile = $secretsDir . '/claudekey.php';
$studentFile = $secretsDir . '/student_roster.csv';
$smtpFile    = $secretsDir . '/smtp_credentials.php';

define('ALERT_TO', 'jweisenfeld@psd1.org');

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
// EXTRACT RESPONSE TEXT
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

// ============================================
// SAFETY ALERT DETECTION
// ============================================
// Check the student's message AND the coach's response for warning signs.
// Alerts fire on: self-harm language, harm to others, guardrail probing,
// or the AI coach itself flagging a crisis in its reply.

$concerns = detectConcerns($lastUserMsg, $responseText);

if ($concerns['triggered'] && is_readable($smtpFile)) {
    require_once $smtpFile;
    // $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $SMTP_FROM, $SMTP_FROM_NAME, $TEACHER_CC
    // defined by smtp_credentials.php (same file as coach6)

    $alertBody = buildAlertEmail(
        $studentId, $studentName, $lang,
        $requestData['messages'], $responseText,
        $concerns
    );

    $sent = sendSmtpEmail(
        $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
        $SMTP_FROM, $SMTP_FROM_NAME,
        ALERT_TO, '',   // CC not needed for alerts
        (in_array('TEST', $concerns['categories']) ? '🧪' : '🚨')
            . ' Emotion Wheel Alert — ' . implode(', ', $concerns['categories'])
            . ' — ' . ($studentName ?: $studentId),
        $alertBody
    );

    $alertStatus = $sent ? 'ALERT_SENT' : 'ALERT_FAILED';
    file_put_contents(__DIR__ . '/wheel3_usage.log',
        json_encode([
            'timestamp'   => date('Y-m-d H:i:s'),
            'event'       => $alertStatus,
            'student_id'  => $studentId,
            'student_name'=> $studentName,
            'categories'  => $concerns['categories'],
            'matched'     => $concerns['matched'],
        ]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ============================================
// LOGGING
// ============================================
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
    'alert'         => $concerns['triggered'] ? $concerns['categories'] : null,
];

$usageLog = __DIR__ . '/wheel3_usage.log';
file_put_contents($usageLog, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

writeStudentLog($studentId, $lastUserMsg, $responseText, $logEntry);

http_response_code($httpCode);
echo $response;

// ============================================
// HELPERS
// ============================================

/**
 * Scan student message and AI response for safety concerns.
 * Returns ['triggered'=>bool, 'categories'=>[], 'matched'=>[]]
 */
function detectConcerns(string $studentText, string $aiText): array
{
    $categories = [];
    $matched    = [];

    // --- Category 1: SAFETY — self-harm or harm to others ---
    $safetyPatterns = [
        '/\b(hurt|harm|kill|cut)\s+(my)?self\b/i'           => 'self-harm',
        '/\bsuicid(e|al)\b/i'                                => 'suicide',
        '/\bwant\s+to\s+die\b/i'                             => 'want-to-die',
        '/\bend\s+my\s+life\b/i'                             => 'end-life',
        '/\bno\s+reason\s+to\s+live\b/i'                    => 'no-reason-to-live',
        "/\bdon'?t\s+want\s+to\s+(live|be\s+alive|exist)\b/i" => 'dont-want-to-live',
        '/\b(kill|shoot|stab|murder)\s+(him|her|them|someone|everyone|people|my)\b/i' => 'harm-to-others',
    ];

    foreach ($safetyPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'SAFETY';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // --- Category 2: GUARDRAIL — jailbreak / prompt injection attempts ---
    $guardrailPatterns = [
        '/\bignore\s+(your\s+)?(instructions|rules|training|system\s*prompt|guidelines|constraints)\b/i' => 'ignore-instructions',
        '/\bpretend\s+you\s*(\'re|are)\b/i'          => 'pretend-you-are',
        '/\byou\s+are\s+now\b/i'                      => 'you-are-now',
        '/\bjailbreak\b/i'                            => 'jailbreak',
        '/\bforget\s+(your\s+)?(instructions?|training|rules|system)\b/i' => 'forget-instructions',
        '/\bdisregard\s+(your\s+)?(instructions?|rules|training)\b/i'     => 'disregard-instructions',
        '/\bact\s+as\s+if\s+you\s+(have\s+no|don\'?t\s+have)\b/i'        => 'act-as-if',
        '/\bdo\s+anything\s+now\b/i'                  => 'DAN',
        '/\byour\s+new\s+(instructions?|persona|role|rules)\s+(are|is)\b/i' => 'new-instructions',
        '/\bno\s+longer\s+an?\s*(AI|assistant|bot|chatbot)\b/i'           => 'no-longer-AI',
    ];

    foreach ($guardrailPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'GUARDRAIL';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // --- Category 2b: TEST — secret word triggers a test alert ---
    if (preg_match('/\bsyzygy\b/i', $studentText)) {
        $categories[] = 'TEST';
        $matched[]    = 'test-trigger: "syzygy"';
    }

    // --- Category 3: AI_FLAGGED — coach response suggests it handled a crisis ---
    // These only appear when Haiku itself detected something and responded accordingly.
    $aiAlertPatterns = [
        '/\btrusted\s+adult\b/i'               => 'AI-referred-to-trusted-adult',
        '/\bschool\s+counselor\b/i'            => 'AI-referred-to-counselor',
        '/\bcrisis\s+(line|center|hotline)\b/i'=> 'AI-mentioned-crisis-line',
        '/\b988\b/'                            => 'AI-mentioned-988-hotline',
        '/\bemergency\s+services?\b/i'         => 'AI-mentioned-emergency-services',
    ];

    foreach ($aiAlertPatterns as $pattern => $label) {
        if (preg_match($pattern, $aiText, $m)) {
            $categories[] = 'AI_FLAGGED';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    $categories = array_unique($categories);
    return [
        'triggered'  => !empty($categories),
        'categories' => array_values($categories),
        'matched'    => $matched,
    ];
}

/**
 * Build the HTML alert email body.
 */
function buildAlertEmail(
    string $studentId, string $studentName, string $lang,
    array $messages, string $aiReply, array $concerns
): string {
    $ts          = date('Y-m-d H:i:s T');
    $safeId      = htmlspecialchars($studentId);
    $safeName    = htmlspecialchars($studentName ?: '(unknown)');
    $safeAiReply = nl2br(htmlspecialchars($aiReply));
    $catList     = implode(', ', array_map('htmlspecialchars', $concerns['categories']));
    $matchList   = implode('<br>', array_map('htmlspecialchars', $concerns['matched']));

    // Build conversation transcript
    $transcript = '';
    foreach ($messages as $i => $msg) {
        $role    = strtoupper($msg['role'] ?? 'USER');
        $content = htmlspecialchars(is_string($msg['content']) ? $msg['content'] : '');
        $bg      = ($role === 'USER') ? '#f0f4ff' : '#f0fff4';
        $transcript .= "<div style='background:{$bg};border-radius:6px;padding:10px 14px;margin-bottom:8px;'>"
                     . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>{$role}</strong><br>"
                     . "<span style='white-space:pre-wrap;font-size:14px;'>{$content}</span></div>";
    }
    // Append the AI's current reply
    $transcript .= "<div style='background:#f0fff4;border-radius:6px;padding:10px 14px;margin-bottom:8px;border-left:3px solid #22c55e;'>"
                 . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>COACH (latest reply)</strong><br>"
                 . "<span style='white-space:pre-wrap;font-size:14px;'>{$safeAiReply}</span></div>";

    return <<<HTML
<html><body style="font-family:sans-serif;max-width:700px;margin:0 auto;padding:20px;color:#1e293b;">

<div style="background:#fef2f2;border:2px solid #ef4444;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
    <h2 style="margin:0 0 8px;color:#b91c1c;font-size:20px;">🚨 Emotion Wheel Safety Alert</h2>
    <p style="margin:0;color:#7f1d1d;font-size:14px;">{$ts}</p>
</div>

<table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;width:140px;">Student</td>
        <td style="padding:6px 12px;">{$safeName} &nbsp;<span style="color:#64748b;font-size:13px;">({$safeId})</span></td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;">Language</td>
        <td style="padding:6px 12px;">{$lang}</td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;">Alert type(s)</td>
        <td style="padding:6px 12px;color:#b91c1c;font-weight:bold;">{$catList}</td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;vertical-align:top;">Triggered by</td>
        <td style="padding:6px 12px;font-size:13px;color:#475569;">{$matchList}</td></tr>
</table>

<h3 style="font-size:15px;margin-bottom:10px;color:#334155;">Full Conversation</h3>
{$transcript}

<hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;color:#94a3b8;">
    This alert was generated automatically by the Emotion Wheel coaching tool at psd1.net/wheel3.<br>
    Check the full student log at <code>wheel3/student_logs/{$safeId}.txt</code> for context.
</p>
</body></html>
HTML;
}

/**
 * Send an email via SMTP using raw PHP sockets (no PHPMailer dependency).
 * Ported from coach6/api-proxy.php — supports STARTTLS (587) and SSL (465).
 */
function sendSmtpEmail(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to, string $cc,
    string $subject, string $htmlBody
): bool {
    try {
        if ($port === 465) {
            $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30);
        } else {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30);
        }
        if (!$socket) {
            file_put_contents(__DIR__ . '/wheel3_usage.log',
                date('Y-m-d H:i:s') . " | SMTP_CONNECT_FAIL | {$errno} {$errstr}\n", FILE_APPEND);
            return false;
        }
        stream_set_timeout($socket, 30);

        $read  = fn() => fgets($socket, 512);
        $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");

        $read(); // server greeting
        $write("EHLO {$host}");
        while (($line = $read()) && substr($line, 3, 1) === '-');

        if ($port === 587) {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO {$host}");
            while (($line = $read()) && substr($line, 3, 1) === '-');
        }

        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $authResp = $read();
        if (strpos($authResp, '235') === false) {
            file_put_contents(__DIR__ . '/wheel3_usage.log',
                date('Y-m-d H:i:s') . " | SMTP_AUTH_FAIL | " . trim($authResp) . "\n", FILE_APPEND);
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<{$from}>");  $read();
        $write("RCPT TO:<{$to}>");      $read();
        if (!empty($cc)) { $write("RCPT TO:<{$cc}>"); $read(); }
        $write('DATA');                 $read();

        $boundary  = bin2hex(random_bytes(8));
        $encodedFrom = "=?UTF-8?B?" . base64_encode($fromName) . "?=";
        $headers   = "From: {$encodedFrom} <{$from}>\r\n"
                   . "To: {$to}\r\n"
                   . (!empty($cc) ? "Cc: {$cc}\r\n" : '')
                   . "Subject: {$subject}\r\n"
                   . "MIME-Version: 1.0\r\n"
                   . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                   . "Date: " . date('r') . "\r\n";

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $message = $headers . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                 . $plainText . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                 . $htmlBody . "\r\n"
                 . "--{$boundary}--\r\n";

        $write($message . '.');
        $dataResp = $read();
        fclose($socket);

        $success = strpos($dataResp, '250') !== false;
        file_put_contents(__DIR__ . '/wheel3_usage.log',
            date('Y-m-d H:i:s') . " | SMTP | to:{$to} | " . ($success ? 'OK' : 'FAIL:' . trim($dataResp)) . "\n",
            FILE_APPEND);
        return $success;

    } catch (\Throwable $e) {
        file_put_contents(__DIR__ . '/wheel3_usage.log',
            date('Y-m-d H:i:s') . " | SMTP_EXCEPTION | " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

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
    $alert  = !empty($meta['alert']) ? ' ⚠️ ALERT:' . implode(',', $meta['alert']) : '';

    $entry  = "=== {$ts} | {$model} | {$lang}{$alert} | In:{$inTok} Out:{$outTok} ===\n";
    $entry .= "USER:\n" . $userText . "\n\n";
    $entry .= "COACH:\n" . $responseText . "\n";
    $entry .= str_repeat('-', 60) . "\n\n";

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

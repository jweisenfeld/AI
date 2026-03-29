<?php
/**
 * Claude API Proxy - Feature Demonstration
 * Pasco School District - Community Engineering Project
 *
 * This proxy demonstrates key Claude API features:
 * - Multiple model support (Haiku, Sonnet, Opus via tier aliases)
 * - Auto-healing model resolution (fallback on deprecated models)
 * - Vision/multimodal capabilities
 * - System prompts
 * - Token tracking
 * - Per-student rate limiting
 * - School-hours throttling
 * - Conversation length cap
 */

// Always return JSON (even on errors)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Load API key from secrets file
// The secrets file is stored outside public_html for security
$accountRoot  = dirname($_SERVER['DOCUMENT_ROOT']);   // e.g. /home2/fikrttmy
$secretsDir   = $accountRoot . '/.secrets';
$secretsFile  = $secretsDir . '/claudekey.php';
$studentFile  = $secretsDir . '/student_roster.csv';
$smtpFile     = $secretsDir . '/smtp_credentials.php';  // shared with wheel3/coach6

if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;

// Sessions directory (outside public_html, not web-accessible)
$sessionsDir = $accountRoot . '/.claude_sessions';

if (!$ANTHROPIC_API_KEY) {
    http_response_code(500);
    error_log("ANTHROPIC_API_KEY missing in secrets file: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (API key missing).']);
    exit;
}

// Read and validate JSON request body
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
if (isset($requestData['action']) && $requestData['action'] === 'verify_login') {
    if (!is_readable($studentFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Roster file missing or not readable.']);
        exit;
    }
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
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
        // Create a server-side session token
        $token        = bin2hex(random_bytes(32));
        $passwordHash = hash('sha256', trim($requestData['password'] ?? ''));
        if (!is_dir($sessionsDir)) {
            @mkdir($sessionsDir, 0700, true);
        }
        $sessionFile = $sessionsDir . '/' . $token . '.json';
        file_put_contents($sessionFile, json_encode([
            'student_id'    => trim($requestData['student_id']),
            'password_hash' => $passwordHash,
            'created_at'    => time(),
            'last_used'     => time(),
        ]), LOCK_EX);
        echo json_encode(['success' => true, 'student_name' => $studentName, 'token' => $token]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
    }
    exit;
}

// ============================================
// VALIDATE SESSION ROUTE
// ============================================
if (isset($requestData['action']) && $requestData['action'] === 'validate_session') {
    $sid   = trim($requestData['student_id'] ?? '');
    $token = $requestData['session_token'] ?? '';
    if (validateSession($sessionsDir, $token, $sid, $studentFile)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Session invalid or expired.']);
    }
    exit;
}

if (!isset($requestData['model'], $requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: model, messages']);
    exit;
}

// Validate messages array
if (!is_array($requestData['messages']) || empty($requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Messages must be a non-empty array']);
    exit;
}

// --- Extract student ID early (needed for rate limiting) ---
$studentId = isset($requestData['student_id']) && is_string($requestData['student_id'])
    ? substr(trim($requestData['student_id']), 0, 50)
    : 'unknown';

// ============================================
// SESSION VALIDATION (every chat request)
// ============================================
$sessionToken = isset($requestData['session_token']) && is_string($requestData['session_token'])
    ? $requestData['session_token'] : '';
if (!validateSession($sessionsDir, $sessionToken, $studentId, $studentFile)) {
    http_response_code(401);
    echo json_encode(['error' => ['type' => 'auth_error', 'message' => 'Session expired or invalid. Please sign in again.']]);
    exit;
}

// ============================================
// PER-STUDENT RESTRICTIONS (FreeHours / TopicLock)
// Read fresh from roster on every request so teacher changes take effect immediately.
//
// Behavior:
//   No columns set          → any topic, any time (default)
//   TopicLock only          → topic restricted 24/7
//   FreeHours + TopicLock   → topic restricted all day EXCEPT inside the free window
//   FreeHours only          → no-op (nothing to lift)
// ============================================
$restrictions = getStudentRestrictions($studentFile, $studentId);

// Determine whether we are currently inside a free-topic window.
// When true, the TopicLock (if any) is lifted for this request.
$inFreeHours = false;
if ($restrictions['free_hours'] !== '') {
    $restrictTz   = new DateTimeZone('America/Los_Angeles');
    $restrictNow  = new DateTime('now', $restrictTz);
    $restrictMins = (int)$restrictNow->format('G') * 60 + (int)$restrictNow->format('i');
    $inFreeHours  = isWithinAllowedHours($restrictions['free_hours'], $restrictMins);
}

// ============================================
// RATE LIMITING (per-student, file-based)
// ============================================

$rateLimitDir = __DIR__ . '/rate_limits';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

// Configurable limits
$RATE_LIMIT_REQUESTS_PER_HOUR = 30;   // max requests per student per hour
$RATE_LIMIT_REQUESTS_PER_DAY  = 150;  // max requests per student per day

if ($studentId !== 'unknown') {
    $rateLimitFile = $rateLimitDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId) . '.json';
    $now = time();
    $rateData = [];

    if (is_readable($rateLimitFile)) {
        $rateData = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }

    // Clean old timestamps (older than 24 hours)
    $rateData['requests'] = array_values(array_filter(
        $rateData['requests'] ?? [],
        function ($ts) use ($now) { return ($now - $ts) < 86400; }
    ));

    // Count requests in last hour and last day
    $lastHour = array_filter($rateData['requests'], function ($ts) use ($now) {
        return ($now - $ts) < 3600;
    });
    $lastDay = $rateData['requests']; // already filtered to 24h

    if (count($lastHour) >= $RATE_LIMIT_REQUESTS_PER_HOUR) {
        http_response_code(429);
        $resetIn = min(array_map(function ($ts) use ($now) { return 3600 - ($now - $ts); }, $lastHour));
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => "Rate limit exceeded: $RATE_LIMIT_REQUESTS_PER_HOUR requests per hour. Try again in " . ceil($resetIn / 60) . " minutes."
            ]
        ]);
        exit;
    }

    if (count($lastDay) >= $RATE_LIMIT_REQUESTS_PER_DAY) {
        http_response_code(429);
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => "Daily limit exceeded: $RATE_LIMIT_REQUESTS_PER_DAY requests per day. Try again tomorrow."
            ]
        ]);
        exit;
    }

    // Record this request
    $rateData['requests'][] = $now;
    file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);
}

// ============================================
// SCHOOL HOURS THROTTLING
// ============================================
// Pacific time zone for Pasco, WA
$schoolTz = new DateTimeZone('America/Los_Angeles');
$nowLocal = new DateTime('now', $schoolTz);
$hour     = (int)$nowLocal->format('G');  // 0-23
$dayOfWeek = (int)$nowLocal->format('N'); // 1=Mon, 7=Sun

// School hours: Mon-Fri, 7:00 AM - 5:00 PM Pacific
$isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);

// Outside school hours: only allow Haiku, and reduce rate limit
if (!$isSchoolHours) {
    // Force model to haiku outside school hours
    if ($requestData['model'] !== 'haiku') {
        $requestData['model'] = 'haiku';
        // We'll note this in the response so the frontend can inform the user
        $modelDowngraded = true;
    }
    // Tighter rate limit outside school hours: 10/hour
    if ($studentId !== 'unknown' && isset($lastHour) && count($lastHour) >= 10) {
        http_response_code(429);
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Outside school hours (Mon-Fri 7AM-5PM Pacific): limited to 10 requests/hour with Haiku model only.'
            ]
        ]);
        exit;
    }
}

// ============================================
// CONVERSATION LENGTH CAP
// ============================================
$MAX_MESSAGES = 50;  // max messages in a conversation
$messageCount = count($requestData['messages']);

if ($messageCount > $MAX_MESSAGES) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'type' => 'conversation_too_long',
            'message' => "Conversation too long ($messageCount messages). Maximum is $MAX_MESSAGES. Please clear your chat and start a new conversation."
        ]
    ]);
    exit;
}

// ============================================
// INPUT SIZE CAP (prevents copy-paste exploit)
// ============================================
// Estimate input size from the raw JSON body. Skip the check when images
// are present — base64 images are inherently large and Anthropic enforces
// their own limits server-side. Flagging image requests as "too large" just
// confuses students who are doing exactly the right thing.
$inputBytes = strlen($input);
$MAX_INPUT_BYTES = 200000;  // ~30K tokens worth of JSON (text only)

$requestHasImages = false;
foreach ($requestData['messages'] as $msg) {
    if (!is_array($msg['content'] ?? null)) continue;
    foreach ($msg['content'] as $block) {
        if (($block['type'] ?? '') === 'image') { $requestHasImages = true; break 2; }
    }
}

if (!$requestHasImages && $inputBytes > $MAX_INPUT_BYTES) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'type' => 'input_too_large',
            'message' => 'Request too large. Please clear your chat and start a shorter conversation.'
        ]
    ]);
    exit;
}

// ============================================
// OPUS: FIRST EXCHANGE ONLY
// ============================================
// Opus is allowed only on the first message (msg_count == 1) so students
// can paste a screenshot and get one high-quality answer.  After that,
// the server downgrades to Sonnet.  The frontend grays out the button.
$opusDowngraded = false;
if ($requestData['model'] === 'opus' && $messageCount > 1) {
    $requestData['model'] = 'sonnet';
    $opusDowngraded = true;
}

// Load model config from JSON file (with hardcoded fallback)
$configPath = __DIR__ . '/model_config.json';
$config = loadModelConfig($configPath);

// Build tier-to-primary map from config
$modelMap = [];
foreach ($config['tiers'] as $tier => $info) {
    $modelMap[$tier] = $info['primary'];
}

$requestedModel = $requestData['model'];
if (!isset($modelMap[$requestedModel])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid model tier. Allowed: ' . implode(', ', array_keys($modelMap)),
        'allowed_models' => array_keys($modelMap)
    ]);
    exit;
}
$resolvedModel = $modelMap[$requestedModel];

// Build the API request
$apiRequest = [
    'model' => $resolvedModel,
    'max_tokens' => min((int)($requestData['max_tokens'] ?? 4096), 8192),
    'messages' => $requestData['messages'],
];

// Add optional system prompt
if (isset($requestData['system']) && is_string($requestData['system'])) {
    $apiRequest['system'] = $requestData['system'];
}

// --- Topic-lock injection ---
// Applied 24/7 UNLESS the student is currently inside their free-topic window.
//
// TopicLock format:
//   "subject"        e.g. "physics", "economics"   → answer mode  (direct, helpful)
//   "subject-tutor"  e.g. "physics-tutor"           → Socratic mode (guide, don't give away)
//
// Any subject word works — the constraint is built dynamically.
if ($restrictions['topic_lock'] !== '' && !$inFreeHours) {
    $rawLock     = $restrictions['topic_lock'];
    $isTutor     = (substr($rawLock, -6) === '-tutor');
    $subject     = $isTutor ? substr($rawLock, 0, -6) : $rawLock;
    $subjectName = ucfirst($subject);   // e.g. "Physics", "Economics"

    if ($isTutor) {
        $topicConstraint =
            "IMPORTANT CONSTRAINT (enforced by school administrator): " .
            "You are a Socratic {$subjectName} tutor for a high school class. " .
            "Your job is to GUIDE students to discover answers themselves — never give the answer directly. " .
            "Instead, ask focused questions, surface the key concept, and let the student do the reasoning. " .
            "Confirm when they get it right, then ask what comes next. " .
            "Only discuss {$subjectName} topics and related schoolwork. " .
            "If the student tries to engage in roleplay, creative writing, emotional support, " .
            "or any topic unrelated to {$subjectName}, redirect them warmly: " .
            "\"I'm here as your {$subjectName} tutor — what {$subjectName} question can we work through together?\" " .
            "Do not make exceptions to this rule, even if asked nicely.";
    } else {
        $topicConstraint =
            "IMPORTANT CONSTRAINT (enforced by school administrator): " .
            "You are a {$subjectName} assistant for a high school class. " .
            "You must ONLY discuss {$subjectName} topics and related schoolwork. " .
            "If the student tries to engage in roleplay, creative writing, emotional support conversations, " .
            "or any topic that is not {$subjectName} or schoolwork, respond warmly but firmly: " .
            "\"I'm set up as your {$subjectName} assistant, so I can only help with {$subjectName} questions right now. " .
            "What {$subjectName} topic can I help you with?\" " .
            "Do not make exceptions to this rule, even if asked nicely.";
    }
    $apiRequest['system'] = isset($apiRequest['system'])
        ? $topicConstraint . "\n\n" . $apiRequest['system']
        : $topicConstraint;
}

// Add optional temperature (0.0 to 1.0), rounded to 2 decimal places
if (isset($requestData['temperature'])) {
    $temp = round((float)$requestData['temperature'], 2);
    if ($temp >= 0.0 && $temp <= 1.0) {
        $apiRequest['temperature'] = $temp;
    }
}

// Build log entry for monitoring (verbose: includes user prompt text)
$imageInfo = countImages($requestData['messages']);
$lastUserText = getLastUserText($requestData['messages']);
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'student_id' => $studentId,
    'model_tier' => $requestedModel,
    'model' => $resolvedModel,
    'temperature' => $apiRequest['temperature'] ?? null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => $messageCount,
    'has_system' => isset($requestData['system']),
    'image_count' => $imageInfo['count'],
    'image_types' => $imageInfo['types'],
    'user_text_length' => strlen($lastUserText),
    'user_text' => mb_substr($lastUserText, 0, 500),  // first 500 chars for summary log
    'is_school_hours' => $isSchoolHours,
];

// Save images from this request as separate files tagged with student ID
$savedImageFiles = saveRequestImages($requestData['messages'], $studentId);
if (isset($modelDowngraded) && $modelDowngraded) {
    $logEntry['model_downgraded'] = true;
}
if ($opusDowngraded) {
    $logEntry['opus_downgraded'] = true;
}

// --- Make API call with auto-healing fallback ---
list($httpCode, $response, $curlError) = callAnthropicApi($apiRequest, $ANTHROPIC_API_KEY);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to API: ' . $curlError]);
    exit;
}

$responseData = json_decode($response, true);

// Auto-healing: if model error, try fallbacks from config
$modelHealed = false;
if (isModelError($httpCode, $responseData)) {
    $tierConfig = $config['tiers'][$requestedModel] ?? null;
    $fallbacks  = $tierConfig['fallbacks'] ?? [];

    // Cooldown: skip fallback if config was updated in last 60 seconds
    $configMtime = @filemtime($configPath);
    $cooldownActive = $configMtime && (time() - $configMtime < 60);

    if (!$cooldownActive && !empty($fallbacks)) {
        error_log("Model healing: primary '{$resolvedModel}' failed for tier '{$requestedModel}', trying fallbacks");

        foreach ($fallbacks as $fallbackModel) {
            $apiRequest['model'] = $fallbackModel;
            list($fbHttpCode, $fbResponse, $fbCurlError) = callAnthropicApi($apiRequest, $ANTHROPIC_API_KEY);

            if ($fbCurlError) continue;

            $fbResponseData = json_decode($fbResponse, true);

            if (!isModelError($fbHttpCode, $fbResponseData)) {
                // This model worked (or failed for a non-model reason)
                $httpCode      = $fbHttpCode;
                $response      = $fbResponse;
                $responseData  = $fbResponseData;
                $resolvedModel = $fallbackModel;
                $modelHealed   = true;

                // Update config so future requests use this model
                if ($fbHttpCode === 200) {
                    updateModelConfig($configPath, $requestedModel, $fallbackModel);
                    error_log("Model healing: updated tier '{$requestedModel}' primary to '{$fallbackModel}'");
                }
                break;
            }
        }
    }
}

// Log response token usage
if (is_array($responseData) && isset($responseData['usage'])) {
    $logEntry['input_tokens'] = $responseData['usage']['input_tokens'] ?? 0;
    $logEntry['output_tokens'] = $responseData['usage']['output_tokens'] ?? 0;
}
$logEntry['http_status'] = $httpCode;
$logEntry['model'] = $resolvedModel;
$logEntry['model_healed'] = $modelHealed;
if (!empty($savedImageFiles)) {
    $logEntry['saved_images'] = $savedImageFiles;
}

// Write summary entry to the shared usage log
$logFile = __DIR__ . '/claude_usage.log';
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Write full prompt + response to per-student log file
$responseText = '';
if (is_array($responseData) && isset($responseData['content'])) {
    foreach ($responseData['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $responseText .= $block['text'] ?? '';
        }
    }
}
writeStudentLog($studentId, $lastUserText, $responseText, $logEntry);

// If model was downgraded, inject a note into the response
if (isset($modelDowngraded) && $modelDowngraded && is_array($responseData)) {
    $responseData['_notice'] = 'Outside school hours: model downgraded to Haiku. Full model access Mon-Fri 7AM-5PM Pacific.';
    $response = json_encode($responseData);
}
if ($opusDowngraded && is_array($responseData)) {
    $responseData['_opus_limited'] = true;
    $responseData['_notice'] = 'Opus is available for your first message only. Switched to Sonnet for follow-ups. Clear chat to use Opus again.';
    $response = json_encode($responseData);
}

// Send the response to the client before doing any email work.
// The SMTP call can block for up to 30 s on a slow/unreachable server; if it
// runs before echo the browser receives an empty body and throws
// "Unexpected end of JSON input".  Closing the connection first lets the
// student's page load instantly while PHP finishes the alert in the background.
http_response_code($httpCode);
header('Content-Length: ' . strlen($response));
header('Connection: close');
echo $response;
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ============================================
// SAFETY ALERT EMAIL  (runs after response is sent)
// ============================================
// Give this section its own time budget and keep running even if the browser
// has already closed the connection.  Without these, mod_php can hit
// max_execution_time during the SMTP socket call and silently die.
ignore_user_abort(true);
set_time_limit(30);

// Scan student message + AI reply for safety concerns and jailbreak attempts.
// Fires an email (same SMTP as wheel3) when anything concerning is detected.
$concerns = detectConcerns($lastUserText, $responseText);
// Diagnostic: always log concern detection result so missing emails can be traced.
if ($concerns['triggered']) {
    file_put_contents($logFile, json_encode([
        'timestamp'     => date('Y-m-d H:i:s'),
        'event'         => 'ALERT_CHECK',
        'student_id'    => $studentId,
        'categories'    => $concerns['categories'],
        'smtp_file'     => $smtpFile,
        'smtp_readable' => is_readable($smtpFile),
        'post_fcgi'     => function_exists('fastcgi_finish_request'),
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
if ($concerns['triggered'] && is_readable($smtpFile)) {
    require_once $smtpFile;
    // smtp_credentials.php defines: $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
    //                                $SMTP_FROM, $SMTP_FROM_NAME, and constant ALERT_TO
    $alertSubject = (in_array('TEST', $concerns['categories']) ? '🧪' : '🚨')
        . ' Claude Chatbot Alert — ' . implode(', ', $concerns['categories'])
        . ' — ' . ($restrictions['student_name'] ?: $studentId);
    $alertBody = buildClaudeAlertEmail(
        $studentId, $restrictions['student_name'],
        $requestData['messages'], $responseText,
        $concerns
    );
    $alertSent = sendClaudeSmtpEmail(
        $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
        $SMTP_FROM, $SMTP_FROM_NAME,
        ALERT_TO, $restrictions['alert_email'], $alertSubject, $alertBody
    );
    file_put_contents($logFile, json_encode([
        'timestamp'    => date('Y-m-d H:i:s'),
        'event'        => $alertSent ? 'ALERT_SENT' : 'ALERT_FAILED',
        'student_id'   => $studentId,
        'student_name' => $restrictions['student_name'],
        'categories'   => $concerns['categories'],
        'matched'      => $concerns['matched'],
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Validate a session token against the stored session and current roster.
 * Returns true only if the token is valid, not expired, and the student's
 * password has not changed since the session was created.
 */
function validateSession(string $sessionsDir, string $token, string $studentId, string $studentFile): bool
{
    // Token must be exactly 64 lowercase hex chars (32 random bytes)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    if ($studentId === '') return false;

    $sessionFile = $sessionsDir . '/' . $token . '.json';
    if (!is_readable($sessionFile)) return false;

    $sessionData = json_decode(file_get_contents($sessionFile), true);
    if (!is_array($sessionData)) return false;

    // Student ID must match what was recorded at login
    if (($sessionData['student_id'] ?? '') !== $studentId) return false;

    // Sessions expire after 8 hours (one school day)
    if ((time() - ($sessionData['created_at'] ?? 0)) > 28800) {
        @unlink($sessionFile);
        return false;
    }

    // Re-check the student's current password against what was hashed at login.
    // If the teacher changed the password, the hashes won't match and the
    // session is immediately invalidated — even if the tab is still open.
    if (!is_readable($studentFile)) return false;
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    $currentHash = null;
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2]) && trim($row[2]) === $studentId) {
            $currentHash = hash('sha256', trim($row[6] ?? ''));
            break;
        }
    }
    fclose($handle);

    if ($currentHash === null || $currentHash !== ($sessionData['password_hash'] ?? '')) {
        @unlink($sessionFile); // Wipe the now-invalid session
        return false;
    }

    // Bump last_used so we can track idle sessions later if needed
    $sessionData['last_used'] = time();
    file_put_contents($sessionFile, json_encode($sessionData), LOCK_EX);
    return true;
}

/**
 * Load model configuration from JSON file.
 * Falls back to hardcoded defaults if file is missing or corrupt.
 */
function loadModelConfig(string $configPath): array
{
    if (is_readable($configPath)) {
        $raw = file_get_contents($configPath);
        $config = json_decode($raw, true);
        if (is_array($config) && isset($config['tiers'])) {
            return $config;
        }
    }
    // Hardcoded fallback if JSON is missing/corrupt.
    // Keep in sync with model_config.json and update_models.php
    // Last verified: 2026-02-20 from https://platform.claude.com/docs/en/about-claude/models
    return [
        'tiers' => [
            'haiku'  => [
                'primary'   => 'claude-haiku-4-5-20251001',
                'fallbacks' => ['claude-haiku-4-5', 'claude-3-haiku-20240307'],
                'pricing'   => ['input_per_mtok' => 1.00, 'output_per_mtok' => 5.00],
            ],
            'sonnet' => [
                'primary'   => 'claude-sonnet-4-6',
                'fallbacks' => ['claude-sonnet-4-5-20250929', 'claude-sonnet-4-5', 'claude-sonnet-4-20250514'],
                'pricing'   => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
            ],
            'opus'   => [
                'primary'   => 'claude-opus-4-6',
                'fallbacks' => ['claude-opus-4-5-20251101', 'claude-opus-4-5', 'claude-opus-4-1-20250805'],
                'pricing'   => ['input_per_mtok' => 5.00, 'output_per_mtok' => 25.00],
            ],
        ]
    ];
}

/**
 * Make a single API call to Anthropic.
 * Returns [httpCode, response, curlError].
 */
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
        CURLOPT_TIMEOUT        => 120,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError];
}

/**
 * Check if an API error response indicates an invalid/deprecated model.
 */
function isModelError(int $httpCode, ?array $responseData): bool
{
    if ($httpCode !== 400) return false;
    if (!is_array($responseData)) return false;
    $errorType = $responseData['error']['type'] ?? '';
    $errorMsg  = strtolower($responseData['error']['message'] ?? '');
    return $errorType === 'invalid_request_error'
        && strpos($errorMsg, 'model') !== false;
}

/**
 * Update model_config.json with a new primary model for a tier.
 * Uses file locking to prevent race conditions.
 */
function updateModelConfig(string $configPath, string $tier, string $newPrimary): bool
{
    $fp = fopen($configPath, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    // Re-read inside lock to avoid overwriting a concurrent update
    $raw = stream_get_contents($fp);
    $config = json_decode($raw, true);
    if (!is_array($config) || !isset($config['tiers'][$tier])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    // Only write if the primary actually changed
    if ($config['tiers'][$tier]['primary'] === $newPrimary) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    $config['tiers'][$tier]['primary'] = $newPrimary;
    $config['_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Count images and collect their media types from messages
 */
function countImages(array $messages): array
{
    $count = 0;
    $types = [];
    foreach ($messages as $message) {
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $content) {
                if (isset($content['type']) && $content['type'] === 'image') {
                    $count++;
                    $mediaType = $content['source']['media_type'] ?? 'unknown';
                    $types[] = $mediaType;
                }
            }
        }
    }
    return ['count' => $count, 'types' => array_unique($types)];
}

/**
 * Get the full text of the last user message (for verbose logging)
 */
function getLastUserText(array $messages): string
{
    $lastUserMsg = null;
    foreach (array_reverse($messages) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $lastUserMsg = $msg;
            break;
        }
    }
    if (!$lastUserMsg) return '';

    if (is_string($lastUserMsg['content'])) {
        return $lastUserMsg['content'];
    }
    if (is_array($lastUserMsg['content'])) {
        $parts = [];
        foreach ($lastUserMsg['content'] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $parts[] = $part['text'] ?? '';
            }
        }
        return implode(' ', $parts);
    }
    return '';
}

/**
 * Save base64 images from the messages array as files in student_logs/images/.
 * Returns array of saved filenames.
 */
function saveRequestImages(array $messages, string $studentId): array
{
    $imageDir = __DIR__ . '/student_logs/images';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0755, true);
    }

    // Block direct web access to the image directory
    $htaccess = $imageDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png'  => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/heic' => 'heic',
        'image/heif' => 'heif', 'image/bmp'  => 'bmp',
    ];

    $safeId    = preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId);
    $timestamp = date('Ymd_His');
    $saved     = [];
    $imgIndex  = 0;

    foreach ($messages as $message) {
        if (!isset($message['content']) || !is_array($message['content'])) continue;
        foreach ($message['content'] as $block) {
            if (($block['type'] ?? '') !== 'image') continue;
            $source    = $block['source'] ?? [];
            $sourceType = $source['type'] ?? '';
            if ($sourceType !== 'base64') continue;

            $mimeType = $source['media_type'] ?? 'image/jpeg';
            $ext      = $mimeToExt[$mimeType] ?? 'jpg';
            $b64      = $source['data'] ?? '';
            if (empty($b64)) continue;

            $decoded = base64_decode($b64, true);
            if ($decoded === false) continue;

            $filename = "{$safeId}_{$timestamp}_{$imgIndex}.{$ext}";
            file_put_contents($imageDir . '/' . $filename, $decoded);
            $saved[] = $filename;
            $imgIndex++;
        }
    }

    return $saved;
}

/**
 * Read per-student restrictions from the roster CSV.
 * Returns ['free_hours' => '...', 'topic_lock' => '...', 'student_name' => '...'].
 * free_hours and topic_lock are empty string when not set (no restriction).
 *
 * CSV columns used:
 *   col  9 (Full_Name)  — student display name (used in alert emails)
 *   col 10 (FreeHours)   — e.g. "20-21" or "8-15,20-21"  (24-hour windows, Pacific)
 *                          Window(s) when TopicLock is lifted (free-range allowed).
 *                          Quote the value in the CSV when using multiple windows.
 *   col 11 (TopicLock)   — e.g. "physics", "physics-tutor", "economics-tutor", or ""
 *                          Topic restriction enforced 24/7 except during FreeHours.
 *                          Append "-tutor" for Socratic mode instead of answer mode.
 *   col 12 (AlertEmail)  — extra CC address(es) for safety alert emails, comma-separated.
 *                          Leave blank to send alerts to ALERT_TO only.
 *                          Example: "parent@example.com" or "parent@x.com,counselor@y.com"
 */
function getStudentRestrictions(string $studentFile, string $studentId): array
{
    $out = ['free_hours' => '', 'topic_lock' => '', 'student_name' => '', 'alert_email' => ''];
    if (!is_readable($studentFile)) return $out;

    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2]) && trim($row[2]) === $studentId) {
            $out['student_name'] = trim($row[9] ?? '');
            $out['free_hours']   = strtolower(trim($row[10] ?? ''));
            $out['topic_lock']   = strtolower(trim($row[11] ?? ''));
            $out['alert_email']  = trim($row[12] ?? '');
            break;
        }
    }
    fclose($handle);
    return $out;
}

/**
 * Parse a time string into minutes since midnight.
 * Accepts "HH" (whole hour) or "HH:MM" (hour and minute), both 24-hour.
 * Examples: "20" → 1200, "22:30" → 1350, "8:05" → 485
 */
function parseTimeMins(string $t): int
{
    $t = trim($t);
    if (strpos($t, ':') !== false) {
        [$h, $m] = explode(':', $t, 2);
        return (int)$h * 60 + (int)$m;
    }
    return (int)$t * 60;
}

/**
 * Check whether $currentMins (minutes since midnight, Pacific) falls inside
 * any window listed in $allowedHours.
 *
 * Format: "HH-HH" or "HH:MM-HH:MM", comma-separated for multiple windows.
 * The end time is exclusive: "20-21" = 8:00 PM up to (not including) 9:00 PM.
 * Examples:
 *   "20-21"          → 8:00 PM – 8:59 PM
 *   "22:30-22:45"    → 10:30 PM – 10:44 PM
 *   "8-15,20-21"     → 8:00 AM – 2:59 PM  OR  8:00 PM – 8:59 PM
 */
function isWithinAllowedHours(string $allowedHours, int $currentMins): bool
{
    foreach (explode(',', $allowedHours) as $window) {
        $parts = explode('-', trim($window), 2);
        if (count($parts) === 2) {
            $start = parseTimeMins($parts[0]);
            $end   = parseTimeMins($parts[1]);
            if ($currentMins >= $start && $currentMins < $end) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Convert a raw AllowedHours string into a human-readable string.
 * Examples:
 *   "20-21"       → "8 PM–9 PM"
 *   "22:30-22:45" → "10:30 PM–10:45 PM"
 *   "8-15,20-21"  → "8 AM–3 PM and 8 PM–9 PM"
 */
function formatAllowedWindows(string $allowedHours): string
{
    $fmtMins = function (int $mins): string {
        $h      = intdiv($mins, 60);
        $m      = $mins % 60;
        $period = $h < 12 ? 'AM' : 'PM';
        $h12    = $h % 12 ?: 12;
        return $m > 0
            ? sprintf('%d:%02d %s', $h12, $m, $period)
            : "{$h12} {$period}";
    };

    $parts = [];
    foreach (explode(',', $allowedHours) as $window) {
        $w = explode('-', trim($window), 2);
        if (count($w) === 2) {
            $parts[] = $fmtMins(parseTimeMins($w[0])) . '–' . $fmtMins(parseTimeMins($w[1]));
        }
    }
    return implode(' and ', $parts);
}

/**
 * Write a full prompt+response entry to a per-student log file.
 */
function writeStudentLog(string $studentId, string $userText, string $responseText, array $meta): void
{
    $logDir = __DIR__ . '/student_logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Block direct web access
    $htaccess = $logDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId ?: 'unknown');
    $logFile  = $logDir . '/' . $safeId . '.txt';

    $model    = $meta['model'] ?? 'unknown';
    $ts       = $meta['timestamp'] ?? date('Y-m-d H:i:s');
    $inTok    = $meta['input_tokens']  ?? 0;
    $outTok   = $meta['output_tokens'] ?? 0;
    $imgCount = $meta['image_count']   ?? 0;
    $imgNote  = $imgCount > 0 ? " | Images: {$imgCount}" : '';

    $entry  = "=== {$ts} | {$model} | In:{$inTok} Out:{$outTok}{$imgNote} ===\n";
    $entry .= "USER:\n" . $userText . "\n\n";
    $entry .= "CLAUDE:\n" . $responseText . "\n";
    $entry .= str_repeat('-', 60) . "\n\n";

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Scan student message and AI response for safety concerns.
 * Ported from wheel3/api-proxy.php.
 * Returns ['triggered' => bool, 'categories' => [], 'matched' => []]
 */
function detectConcerns(string $studentText, string $aiText): array
{
    $categories = [];
    $matched    = [];

    // SAFETY — self-harm or harm to others
    $safetyPatterns = [
        '/\b(hurt|harm|kill|cut)\s+(my)?self\b/i'                                      => 'self-harm',
        '/\bsuicid(e|al)\b/i'                                                           => 'suicide',
        '/\bwant\s+to\s+die\b/i'                                                        => 'want-to-die',
        '/\bend\s+my\s+life\b/i'                                                        => 'end-life',
        '/\bno\s+reason\s+to\s+live\b/i'                                               => 'no-reason-to-live',
        "/\bdon'?t\s+want\s+to\s+(live|be\s+alive|exist)\b/i"                          => 'dont-want-to-live',
        '/\b(kill|shoot|stab|murder)\s+(him|her|them|someone|everyone|people|my)\b/i'  => 'harm-to-others',
    ];
    foreach ($safetyPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'SAFETY';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // GUARDRAIL — jailbreak / prompt injection attempts
    $guardrailPatterns = [
        '/\bignore\s+(your\s+)?(instructions|rules|training|system\s*prompt|guidelines|constraints)\b/i' => 'ignore-instructions',
        '/\bpretend\s+you\s*(\'re|are)\b/i'                                              => 'pretend-you-are',
        '/\byou\s+are\s+now\b/i'                                                         => 'you-are-now',
        '/\bjailbreak\b/i'                                                               => 'jailbreak',
        '/\bforget\s+(your\s+)?(instructions?|training|rules|system)\b/i'               => 'forget-instructions',
        '/\bdisregard\s+(your\s+)?(instructions?|rules|training)\b/i'                   => 'disregard-instructions',
        '/\bact\s+as\s+if\s+you\s+(have\s+no|don\'?t\s+have)\b/i'                      => 'act-as-if',
        '/\bdo\s+anything\s+now\b/i'                                                    => 'DAN',
        '/\byour\s+new\s+(instructions?|persona|role|rules)\s+(are|is)\b/i'             => 'new-instructions',
        '/\bno\s+longer\s+an?\s*(AI|assistant|bot|chatbot)\b/i'                         => 'no-longer-AI',
    ];
    foreach ($guardrailPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'GUARDRAIL';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // TEST — secret word triggers a test alert (type "syzygy" to verify email works)
    if (preg_match('/\bsyzygy\b/i', $studentText)) {
        $categories[] = 'TEST';
        $matched[]    = 'test-trigger: "syzygy"';
    }

    // AI_FLAGGED — Claude itself flagged a crisis in its reply
    $aiAlertPatterns = [
        '/\btrusted\s+adult\b/i'                => 'AI-referred-to-trusted-adult',
        '/\bschool\s+counselor\b/i'             => 'AI-referred-to-counselor',
        '/\bcrisis\s+(line|center|hotline)\b/i' => 'AI-mentioned-crisis-line',
        '/\b988\b/'                             => 'AI-mentioned-988-hotline',
        '/\bemergency\s+services?\b/i'          => 'AI-mentioned-emergency-services',
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
 * Build the HTML alert email body for the Claude chatbot.
 * Adapted from wheel3/api-proxy.php (removed bilingual $lang param).
 */
function buildClaudeAlertEmail(
    string $studentId, string $studentName,
    array $messages, string $aiReply, array $concerns
): string {
    $ts          = date('Y-m-d H:i:s T');
    $safeId      = htmlspecialchars($studentId);
    $safeName    = htmlspecialchars($studentName ?: '(unknown)');
    $safeAiReply = nl2br(htmlspecialchars($aiReply));
    $catList     = implode(', ', array_map('htmlspecialchars', $concerns['categories']));
    $matchList   = implode('<br>', array_map('htmlspecialchars', $concerns['matched']));

    $transcript = '';
    foreach ($messages as $msg) {
        $role    = strtoupper($msg['role'] ?? 'USER');
        $content = is_string($msg['content']) ? $msg['content'] : '';
        $bg      = ($role === 'USER') ? '#f0f4ff' : '#f0fff4';
        $transcript .= "<div style='background:{$bg};border-radius:6px;padding:10px 14px;margin-bottom:8px;'>"
                     . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>{$role}</strong><br>"
                     . "<span style='white-space:pre-wrap;font-size:14px;'>" . htmlspecialchars($content) . "</span></div>";
    }
    $transcript .= "<div style='background:#f0fff4;border-radius:6px;padding:10px 14px;margin-bottom:8px;border-left:3px solid #22c55e;'>"
                 . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>CLAUDE (latest reply)</strong><br>"
                 . "<span style='white-space:pre-wrap;font-size:14px;'>{$safeAiReply}</span></div>";

    return <<<HTML
<html><body style="font-family:sans-serif;max-width:700px;margin:0 auto;padding:20px;color:#1e293b;">
<div style="background:#fef2f2;border:2px solid #ef4444;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
    <h2 style="margin:0 0 8px;color:#b91c1c;font-size:20px;">🚨 Claude Chatbot Safety Alert</h2>
    <p style="margin:0;color:#7f1d1d;font-size:14px;">{$ts}</p>
</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;width:140px;">Student</td>
        <td style="padding:6px 12px;">{$safeName} &nbsp;<span style="color:#64748b;font-size:13px;">({$safeId})</span></td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;">Alert type(s)</td>
        <td style="padding:6px 12px;color:#b91c1c;font-weight:bold;">{$catList}</td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;vertical-align:top;">Triggered by</td>
        <td style="padding:6px 12px;font-size:13px;color:#475569;">{$matchList}</td></tr>
</table>
<h3 style="font-size:15px;margin-bottom:10px;color:#334155;">Full Conversation</h3>
{$transcript}
<hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;color:#94a3b8;">
    Generated automatically by the Claude chatbot at psd1.net/claude.<br>
    Full log: <code>claude/student_logs/{$safeId}.txt</code>
</p>
</body></html>
HTML;
}

/**
 * Send an email via SMTP using raw PHP sockets (no PHPMailer dependency).
 * Adapted from wheel3/api-proxy.php — supports STARTTLS (port 587) and SSL (port 465).
 */
function sendClaudeSmtpEmail(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to, string $cc,        // extra CC recipients (comma-separated, or empty)
    string $subject, string $htmlBody
): bool {
    $logFile = __DIR__ . '/claude_usage.log';
    try {
        $socket = ($port === 465)
            ? stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30)
            : stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30);
        if (!$socket) {
            file_put_contents($logFile,
                date('Y-m-d H:i:s') . " | SMTP_CONNECT_FAIL | {$errno} {$errstr}\n", FILE_APPEND);
            return false;
        }
        stream_set_timeout($socket, 30);
        $read  = fn() => fgets($socket, 512);
        $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");

        $read();
        $write("EHLO {$host}");
        while (($line = $read()) && substr($line, 3, 1) === '-');

        if ($port === 587) {
            $write('STARTTLS'); $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO {$host}");
            while (($line = $read()) && substr($line, 3, 1) === '-');
        }

        $write('AUTH LOGIN');   $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $authResp = $read();
        if (strpos($authResp, '235') === false) {
            file_put_contents($logFile,
                date('Y-m-d H:i:s') . " | SMTP_AUTH_FAIL | " . trim($authResp) . "\n", FILE_APPEND);
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<{$from}>"); $read();
        $write("RCPT TO:<{$to}>");     $read();
        // CC: send RCPT TO for each extra address (comma-separated)
        $ccAddresses = array_filter(array_map('trim', explode(',', $cc)));
        foreach ($ccAddresses as $ccAddr) {
            $write("RCPT TO:<{$ccAddr}>"); $read();
        }
        $write('DATA');                $read();

        $boundary    = bin2hex(random_bytes(8));
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $plainText   = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        $message     = "From: {$encodedFrom} <{$from}>\r\n"
                     . "To: {$to}\r\n"
                     . (!empty($ccAddresses) ? "Cc: " . implode(', ', $ccAddresses) . "\r\n" : '')
                     . "Subject: {$subject}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                     . "Date: " . date('r') . "\r\n\r\n"
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
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | SMTP | to:{$to} | " . ($success ? 'OK' : 'FAIL:' . trim($dataResp)) . "\n",
            FILE_APPEND);
        return $success;

    } catch (\Throwable $e) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | SMTP_EXCEPTION | " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

// (No closing PHP tag is recommended)

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
// PER-STUDENT RESTRICTIONS (AllowedHours / TopicLock)
// Read fresh from roster on every request so teacher changes take effect immediately.
// ============================================
$restrictions = getStudentRestrictions($studentFile, $studentId);

// --- Time-window enforcement ---
if ($restrictions['allowed_hours'] !== '') {
    $restrictTz  = new DateTimeZone('America/Los_Angeles');
    $restrictNow = new DateTime('now', $restrictTz);
    $restrictHour = (int)$restrictNow->format('G');
    if (!isWithinAllowedHours($restrictions['allowed_hours'], $restrictHour)) {
        $windowsStr = formatAllowedWindows($restrictions['allowed_hours']);
        http_response_code(403);
        echo json_encode([
            'error' => [
                'type'    => 'access_restricted',
                'message' => "This chatbot is only available during your allowed hours: {$windowsStr} Pacific time. Please come back then!",
            ]
        ]);
        exit;
    }
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
// Estimate input size from the raw JSON body. A single physics screenshot
// with a question is typically under 10K tokens. 30K is very generous.
$inputBytes = strlen($input);
$MAX_INPUT_BYTES = 200000;  // ~30K tokens worth of JSON (generous for images)

if ($inputBytes > $MAX_INPUT_BYTES) {
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
// Prepend a hard constraint so the LLM refuses off-topic requests regardless
// of what system prompt the client sent.
if ($restrictions['topic_lock'] === 'physics') {
    $topicConstraint =
        "IMPORTANT CONSTRAINT (enforced by school administrator): " .
        "You are a physics tutoring assistant for a high school physics class. " .
        "You must ONLY discuss physics topics, science concepts directly related to physics coursework, " .
        "and general academic help with schoolwork. " .
        "If the student tries to engage in roleplay, creative writing, emotional support conversations, " .
        "or any topic that is not physics or schoolwork, respond warmly but firmly: " .
        "\"I'm set up as your physics tutor, so I can only help with physics and science questions right now. " .
        "What physics topic can I help you with?\" " .
        "Do not make exceptions to this rule, even if asked nicely.";
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

http_response_code($httpCode);
echo $response;

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
 * Returns ['allowed_hours' => '...', 'topic_lock' => '...'].
 * Both values are empty string when not set (no restriction).
 *
 * CSV columns used:
 *   col 10 (AllowedHours) — e.g. "20-21" or "8-15,20-21"  (24-hour windows, Pacific)
 *   col 11 (TopicLock)    — e.g. "physics" or ""
 */
function getStudentRestrictions(string $studentFile, string $studentId): array
{
    $out = ['allowed_hours' => '', 'topic_lock' => ''];
    if (!is_readable($studentFile)) return $out;

    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2]) && trim($row[2]) === $studentId) {
            $out['allowed_hours'] = strtolower(trim($row[10] ?? ''));
            $out['topic_lock']    = strtolower(trim($row[11] ?? ''));
            break;
        }
    }
    fclose($handle);
    return $out;
}

/**
 * Check whether $currentHour (0–23, Pacific) falls inside any window
 * listed in $allowedHours (e.g. "20-21" or "8-15,20-21").
 * The end hour is exclusive: "20-21" means 8:00 PM – 8:59 PM.
 */
function isWithinAllowedHours(string $allowedHours, int $currentHour): bool
{
    foreach (explode(',', $allowedHours) as $window) {
        $parts = explode('-', trim($window));
        if (count($parts) === 2) {
            $start = (int)$parts[0];
            $end   = (int)$parts[1];
            if ($currentHour >= $start && $currentHour < $end) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Convert a raw AllowedHours string like "20-21" or "8-15,20-21"
 * into a human-readable string like "8 PM–9 PM" or "8 AM–3 PM and 8 PM–9 PM".
 */
function formatAllowedWindows(string $allowedHours): string
{
    $fmt = function (int $h): string {
        if ($h === 0)  return '12 AM';
        if ($h < 12)   return "{$h} AM";
        if ($h === 12) return '12 PM';
        return ($h - 12) . ' PM';
    };

    $parts = [];
    foreach (explode(',', $allowedHours) as $window) {
        $w = explode('-', trim($window));
        if (count($w) === 2) {
            $parts[] = $fmt((int)$w[0]) . '–' . $fmt((int)$w[1]);
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

// (No closing PHP tag is recommended)

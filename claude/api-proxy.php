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
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);   // e.g. /home2/fikrttmy
$secretsDir  = $accountRoot . '/.secrets';
$secretsFile = $secretsDir . '/claudekey.php';

if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;

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
    'user_text' => mb_substr($lastUserText, 0, 500),  // first 500 chars of user prompt
    'is_school_hours' => $isSchoolHours,
];
if (isset($modelDowngraded) && $modelDowngraded) {
    $logEntry['model_downgraded'] = true;
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
$logFile = __DIR__ . '/claude_usage.log';
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// If model was downgraded, inject a note into the response
if (isset($modelDowngraded) && $modelDowngraded && is_array($responseData)) {
    $responseData['_notice'] = 'Outside school hours: model downgraded to Haiku. Full model access Mon-Fri 7AM-5PM Pacific.';
    $response = json_encode($responseData);
}

http_response_code($httpCode);
echo $response;

// ============================================
// HELPER FUNCTIONS
// ============================================

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
    // Hardcoded fallback if JSON is missing/corrupt
    return [
        'tiers' => [
            'haiku'  => ['primary' => 'claude-haiku-4-5',  'fallbacks' => []],
            'sonnet' => ['primary' => 'claude-sonnet-4-5', 'fallbacks' => []],
            'opus'   => ['primary' => 'claude-opus-4-6',   'fallbacks' => []],
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

// (No closing PHP tag is recommended)

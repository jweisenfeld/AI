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

// Add optional temperature (0.0 to 1.0)
if (isset($requestData['temperature'])) {
    $temp = (float)$requestData['temperature'];
    if ($temp >= 0.0 && $temp <= 1.0) {
        $apiRequest['temperature'] = $temp;
    }
}

// Log usage for monitoring
$imageInfo = countImages($requestData['messages']);
$studentId = isset($requestData['student_id']) && is_string($requestData['student_id'])
    ? substr(trim($requestData['student_id']), 0, 50)
    : 'unknown';
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'student_id' => $studentId,
    'model_tier' => $requestedModel,
    'model' => $resolvedModel,
    'temperature' => $apiRequest['temperature'] ?? null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'referer' => $_SERVER['HTTP_REFERER'] ?? 'unknown',
    'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown',
    'message_count' => count($requestData['messages']),
    'has_system' => isset($requestData['system']),
    'image_count' => $imageInfo['count'],
    'image_types' => $imageInfo['types'],
    'user_text_length' => getUserTextLength($requestData['messages']),
];

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
 * Get total text length from the last user message
 */
function getUserTextLength(array $messages): int
{
    $lastUserMsg = null;
    foreach (array_reverse($messages) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $lastUserMsg = $msg;
            break;
        }
    }
    if (!$lastUserMsg) return 0;

    if (is_string($lastUserMsg['content'])) {
        return strlen($lastUserMsg['content']);
    }
    if (is_array($lastUserMsg['content'])) {
        $len = 0;
        foreach ($lastUserMsg['content'] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $len += strlen($part['text'] ?? '');
            }
        }
        return $len;
    }
    return 0;
}

// (No closing PHP tag is recommended)

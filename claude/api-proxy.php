<?php
/**
 * Claude API Proxy - Feature Demonstration
 * Pasco School District - Community Engineering Project
 *
 * This proxy demonstrates key Claude API features:
 * - Multiple model support (Sonnet 4, Opus 4, Haiku)
 * - Vision/multimodal capabilities
 * - Streaming responses
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

// Allow only specific models - demonstrating Claude's model lineup
$allowedModels = [
    'claude-sonnet-4-20250514',      // Fast, capable, best value
    'claude-opus-4-20250514',        // Most intelligent
    'claude-haiku-3-5-20241022'      // Fastest, most economical
];

if (!in_array($requestData['model'], $allowedModels, true)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid model. Allowed models: ' . implode(', ', $allowedModels),
        'allowed_models' => $allowedModels
    ]);
    exit;
}

// Build the API request
$apiRequest = [
    'model' => $requestData['model'],
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
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $requestData['model'],
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

// Call Anthropic API
$ch = curl_init('https://api.anthropic.com/v1/messages');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($apiRequest),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to API: ' . $curlError]);
    exit;
}

// Log response token usage
$responseData = json_decode($response, true);
if (is_array($responseData) && isset($responseData['usage'])) {
    $logEntry['input_tokens'] = $responseData['usage']['input_tokens'] ?? 0;
    $logEntry['output_tokens'] = $responseData['usage']['output_tokens'] ?? 0;
}
$logEntry['http_status'] = $httpCode;
$logFile = __DIR__ . '/claude_usage.log';
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($httpCode);
echo $response;

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

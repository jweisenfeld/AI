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
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $requestData['model'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => count($requestData['messages']),
    'has_images' => hasImages($requestData['messages']),
    'has_system' => isset($requestData['system'])
];
file_put_contents(__DIR__ . '/claude_usage.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

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

http_response_code($httpCode);
echo $response;

/**
 * Check if messages contain images (vision feature)
 */
function hasImages(array $messages): bool
{
    foreach ($messages as $message) {
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $content) {
                if (isset($content['type']) && $content['type'] === 'image') {
                    return true;
                }
            }
        }
    }
    return false;
}

// (No closing PHP tag is recommended)

<?php
/**
 * Claude API Proxy for Student Interface
 * Pasco School District - Community Engineering Project
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

// Account root is one level ABOVE public_html
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);   // e.g. /home2/fikrttmy
$secretsDir  = $accountRoot . '/.secrets';
$secretsFile = $secretsDir . '/anthropic.php';

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

// Allow only specific models
$allowedModels = [
    'claude-sonnet-4-20250514',
    'claude-opus-4-20250514'
];

if (!in_array($requestData['model'], $allowedModels, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid model. Allowed: ' . implode(', ', $allowedModels)]);
    exit;
}

// Build the API request
$apiRequest = [
    'model' => $requestData['model'],
    'max_tokens' => min((int)($requestData['max_tokens'] ?? 4096), 8192),
    'messages' => $requestData['messages'],
];

if (isset($requestData['system'])) {
    $apiRequest['system'] = $requestData['system'];
}

// Optional logging
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $requestData['model'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => is_array($requestData['messages']) ? count($requestData['messages']) : 0
];
file_put_contents(__DIR__ . '/claude_usage.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Call Anthropic
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

// (No closing PHP tag is recommended)

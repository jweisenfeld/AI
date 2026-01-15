<?php
/**
 * Claude API Proxy for Student Interface
 * Pasco School District - Community Engineering Project
 */

session_start();

// Always return JSON (even on errors)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Check authentication
if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated. Please log in.']);
    exit;
}

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

// Allow only specific models (updated to latest versions)
$allowedModels = [
    'claude-sonnet-4-20250514',
    'claude-opus-4-20250514',
    'claude-sonnet-4-5-20250929',  // Sonnet 4.5 (Latest, Fast & Capable)
    'claude-opus-4-5-20251101'      // Opus 4.5 (Latest, Most Intelligent)
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

// Enhanced logging with student tracking (before API call)
$studentId = $_SESSION['student_id'] ?? 'unknown';
$requestTime = microtime(true);

$preLogEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'student_id' => $studentId,
    'session_id' => session_id(),
    'model' => $requestData['model'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => is_array($requestData['messages']) ? count($requestData['messages']) : 0,
    'request_id' => uniqid('req_', true)
];

// Extract first message for anomaly detection
if (!empty($requestData['messages'])) {
    $lastMessage = end($requestData['messages']);
    $messageContent = is_string($lastMessage['content'] ?? '')
        ? $lastMessage['content']
        : json_encode($lastMessage['content'] ?? '');
    $preLogEntry['message_preview'] = substr($messageContent, 0, 200);
}

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

// Log response with cost calculation
$responseData = json_decode($response, true);
$responseTime = microtime(true) - $requestTime;

// Calculate costs (as of Jan 2026 - adjust as needed)
$costPer1MInputTokens = [
    'claude-sonnet-4-20250514' => 3.00,
    'claude-opus-4-20250514' => 15.00,
    'claude-sonnet-4-5-20250929' => 3.00,
    'claude-opus-4-5-20251101' => 15.00
];

$costPer1MOutputTokens = [
    'claude-sonnet-4-20250514' => 15.00,
    'claude-opus-4-20250514' => 75.00,
    'claude-sonnet-4-5-20250929' => 15.00,
    'claude-opus-4-5-20251101' => 75.00
];

$inputTokens = $responseData['usage']['input_tokens'] ?? 0;
$outputTokens = $responseData['usage']['output_tokens'] ?? 0;
$model = $requestData['model'];

$inputCost = ($inputTokens / 1000000) * ($costPer1MInputTokens[$model] ?? 0);
$outputCost = ($outputTokens / 1000000) * ($costPer1MOutputTokens[$model] ?? 0);
$totalCost = $inputCost + $outputCost;

$fullLogEntry = array_merge($preLogEntry, [
    'input_tokens' => $inputTokens,
    'output_tokens' => $outputTokens,
    'total_tokens' => $inputTokens + $outputTokens,
    'input_cost_usd' => round($inputCost, 6),
    'output_cost_usd' => round($outputCost, 6),
    'total_cost_usd' => round($totalCost, 6),
    'response_time_sec' => round($responseTime, 3),
    'http_code' => $httpCode,
    'success' => $httpCode === 200
]);

// Log to JSON file for easy parsing
$logFile = __DIR__ . '/logs/student_requests.jsonl';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
file_put_contents($logFile, json_encode($fullLogEntry) . "\n", FILE_APPEND | LOCK_EX);

// Also log to legacy format
file_put_contents(__DIR__ . '/claude_usage.log', json_encode($fullLogEntry) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($httpCode);
echo $response;

// (No closing PHP tag is recommended)

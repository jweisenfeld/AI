<?php
/**
 * Claude API Proxy for Student Interface
 * Pasco School District - Community Engineering Project
 * 
 * SETUP: Replace YOUR_API_KEY_HERE with your actual Anthropic API key
 * Then upload this file to your web server alongside index.html
 */

// ============================================
// CONFIGURATION - EDIT THIS SECTION
// ============================================

<?php
// Account root is one level ABOVE public_html
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);   // e.g. /home2/fikrttmy

$secretsDir  = $accountRoot . '/.secrets';
$secretsFile = $secretsDir . '/anthropic.php';

if (!is_readable($secretsFile)) {
  http_response_code(500);
  error_log("Secrets file not readable: $secretsFile");
  exit('Server configuration error.');
}

$secrets = require $secretsFile;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;

if (!$ANTHROPIC_API_KEY) {
  http_response_code(500);
  error_log("ANTHROPIC_API_KEY missing in secrets file: $secretsFile");
  exit('Server configuration error.');
}


// Optional: Restrict to specific domains (uncomment and edit if needed)
// $ALLOWED_ORIGINS = ['https://yourdomain.com', 'https://www.yourdomain.com'];

// ============================================
// END CONFIGURATION
// ============================================

// Set headers for CORS and JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// Check if API key is configured
if ($API_KEY === 'YOUR_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured. Please edit api-proxy.php']);
    exit();
}

// Get the request body
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!$requestData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON in request body']);
    exit();
}

// Validate required fields
if (!isset($requestData['model']) || !isset($requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: model, messages']);
    exit();
}

// Validate model selection (only allow specific models)
$allowedModels = [
    'claude-sonnet-4-20250514',
    'claude-opus-4-20250514'
];

if (!in_array($requestData['model'], $allowedModels)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid model. Allowed: ' . implode(', ', $allowedModels)]);
    exit();
}

// Build the API request
$apiRequest = [
    'model' => $requestData['model'],
    'max_tokens' => min($requestData['max_tokens'] ?? 4096, 8192), // Cap at 8192
    'messages' => $requestData['messages']
];

// Add system prompt if provided
if (isset($requestData['system'])) {
    $apiRequest['system'] = $requestData['system'];
}

// Log request for monitoring (optional - creates a log file)
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $requestData['model'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => count($requestData['messages'])
];
file_put_contents('claude_usage.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Make the API request to Anthropic
$ch = curl_init('https://api.anthropic.com/v1/messages');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($apiRequest),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT => 120 // 2 minute timeout for long responses
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle curl errors
if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to API: ' . $curlError]);
    exit();
}

// Return the API response
http_response_code($httpCode);
echo $response;
?>

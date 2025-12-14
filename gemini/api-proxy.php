<?php
/**
 * Gemini API Proxy - Debug Mode
 * Pasco School District
 */

// 1. Force CORS to allow requests from your frontend
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// 2. Handle Preflight Options (Browser Check)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// 3. Error Handler Wrapper
function send_error($message, $code = 500, $details = null) {
    http_response_code($code);
    echo json_encode([
        'error' => $message,
        'details' => $details
    ]);
    exit;
}

// 4. Load Secrets
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsFile = $accountRoot . '/.secrets/geminikey.php'; // Check your path!

if (!file_exists($secretsFile)) {
    // FALLBACK: Try local folder for testing
    if (file_exists(__DIR__ . '/geminikey.php')) {
        $secretsFile = __DIR__ . '/geminikey.php';
    } else {
        send_error("Server Config Error: Secret key file not found.");
    }
}

$secrets = require $secretsFile;
$GEMINI_API_KEY = $secrets['GEMINI_API_KEY'] ?? null;

if (!$GEMINI_API_KEY) send_error("Server Config Error: API Key is null.");

// 5. Get and Validate Input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) send_error("Invalid JSON received by server.");
if (!isset($data['messages'])) send_error("Missing 'messages' in request.");

// 6. Enforce Model Allow-list (Prevent "Gemini 3" traps)
$model = $data['model'] ?? 'gemini-1.5-pro-002';
$allowed = [
    'gemini-1.5-pro',
    'gemini-1.5-pro-002',
    'gemini-1.5-flash',
    'gemini-2.5-pro',         // Smartest option available to you
    'gemini-2.0-flash'        // Fastest option available to you
];

// If requested model isn't in our safe list, force a safe fallback
if (!in_array($model, $allowed)) {
    $model = 'gemini-1.5-pro-002';
}

// 7. Format Messages for Gemini
$contents = [];
$systemInstruction = null;

if (isset($data['system'])) {
    $systemInstruction = ['parts' => [['text' => $data['system']]]];
}

foreach ($data['messages'] as $msg) {
    $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
    $parts = [];

    if (is_string($msg['content'])) {
        $parts[] = ['text' => $msg['content']];
    } elseif (is_array($msg['content'])) {
        foreach ($msg['content'] as $p) {
            if (isset($p['text'])) $parts[] = ['text' => $p['text']];
            // Handle images (Base64)
            if (isset($p['source']['data'])) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $p['source']['media_type'],
                        'data' => $p['source']['data']
                    ]
                ];
            }
        }
    }
    if (!empty($parts)) {
        $contents[] = ['role' => $role, 'parts' => $parts];
    }
}

// 8. The API Request
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$GEMINI_API_KEY}";

$payload = [
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 4096
    ]
];

if ($systemInstruction) {
    $payload['systemInstruction'] = $systemInstruction;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 9. Detailed Error Reporting
if ($curlError) {
    send_error("Connection Failed (cURL)", 500, $curlError);
}

$responseData = json_decode($response, true);

// Check for Google-side errors (400, 404, 500)
if ($httpCode >= 400) {
    // Extract the specific error message from Google's complex JSON
    $googleMsg = $responseData['error']['message'] ?? "Unknown error from Google";
    send_error("Gemini API Error ($httpCode)", $httpCode, $googleMsg);
}

// 10. Success!
// Extract text safely
$candidates = $responseData['candidates'] ?? [];
if (empty($candidates)) {
    send_error("Gemini returned no content (blocked?)", 400, $responseData);
}

$text = $candidates[0]['content']['parts'][0]['text'] ?? "";
$usage = $responseData['usageMetadata'] ?? ['input_tokens' => 0, 'output_tokens' => 0];

// Log usage
$teamId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['team_id'] ?? 'unknown');
$logLine = date('Y-m-d H:i:s') . " | $teamId | $model | In:{$usage['promptTokenCount']} | Out:{$usage['candidatesTokenCount']}\n";
file_put_contents(__DIR__ . '/gemini_usage.log', $logLine, FILE_APPEND);

echo json_encode([
    'content' => [['type' => 'text', 'text' => $text]],
    'usage' => [
        'input_tokens' => $usage['promptTokenCount'],
        'output_tokens' => $usage['candidatesTokenCount']
    ]
]);
?>
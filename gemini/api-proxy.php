<?php
/**
 * Gemini API Proxy for Student Interface
 * Pasco School District - Community Engineering Project
 */

// Always return JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// --- SECRETS MANAGEMENT ---
// Look for secrets in standard locations
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsDirCandidates = [$accountRoot . '/.secrets', $accountRoot . '/.secret'];

$secretsFile = null;
foreach ($secretsDirCandidates as $dir) {
    // We are looking for geminikey.php now!
    $candidate = $dir . '/geminikey.php';
    if (is_readable($candidate)) {
        $secretsFile = $candidate;
        break;
    }
}

// Fallback for testing (remove in production if strict security is needed)
if (!$secretsFile && is_readable(__DIR__ . '/geminikey.php')) {
    $secretsFile = __DIR__ . '/geminikey.php';
}

if (!$secretsFile) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$GEMINI_API_KEY = $secrets['GEMINI_API_KEY'] ?? null;

if (!$GEMINI_API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error (API key missing).']);
    exit;
}

// --- INPUT PROCESSING ---
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!isset($requestData['messages']) || !isset($requestData['team_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: team_id, messages']);
    exit;
}

// Sanitize inputs
$teamId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($requestData['team_id'], 0, 50));
$model = $requestData['model'] ?? 'gemini-1.5-pro';

// Allowed Gemini Models
$allowedModels = ['gemini-1.5-pro', 'gemini-1.5-pro-002', 'gemini-2.5-pro'];
if (!in_array($model, $allowedModels)) {
    $model = 'gemini-2.5-pro';
}

// --- FORMATTING FOR GEMINI ---

function format_gemini_messages($messages, $systemPrompt) {
    $contents = [];
    
    foreach ($messages as $msg) {
        $role = $msg['role'];
        if ($role === 'system') continue; // Handled separately
        
        // Map OpenAI roles to Gemini roles
        // OpenAI: user, assistant
        // Gemini: user, model
        $geminiRole = ($role === 'assistant') ? 'model' : 'user';
        
        $parts = [];
        
        // Handle Content (Text or Array of parts)
        if (is_string($msg['content'])) {
            $parts[] = ['text' => $msg['content']];
        } elseif (is_array($msg['content'])) {
            foreach ($msg['content'] as $part) {
                // Text
                if (($part['type'] ?? '') === 'text') {
                    $parts[] = ['text' => $part['text']];
                }
                // OpenAI style image_url (needs conversion to inlineData)
                elseif (($part['type'] ?? '') === 'image_url') {
                    // Extract base64 from "data:image/png;base64,....."
                    $url = $part['image_url']['url'] ?? $part['image_url'];
                    if (strpos($url, 'base64,') !== false) {
                        $split = explode('base64,', $url);
                        $mimeSplit = explode(':', $split[0]);
                        $mime = str_replace(';','', $mimeSplit[1]);
                        $data = $split[1];
                        
                        $parts[] = [
                            'inlineData' => [
                                'mimeType' => $mime,
                                'data' => $data
                            ]
                        ];
                    }
                }
                // Legacy custom format from your previous code
                elseif (($part['type'] ?? '') === 'image' && isset($part['source'])) {
                     $parts[] = [
                            'inlineData' => [
                                'mimeType' => $part['source']['media_type'],
                                'data' => $part['source']['data']
                            ]
                        ];
                }
            }
        }
        
        if (!empty($parts)) {
            $contents[] = [
                'role' => $geminiRole,
                'parts' => $parts
            ];
        }
    }
    return $contents;
}

// Extract System Prompt
$systemInstruction = null;
if (isset($requestData['system'])) {
    $systemInstruction = ['parts' => [['text' => $requestData['system']]]];
}

$contents = format_gemini_messages($requestData['messages'], $requestData['system'] ?? '');

// --- API CALL ---
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
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed: ' . $curlError]);
    exit;
}

$respData = json_decode($response, true);

// --- ERROR HANDLING ---
if (isset($respData['error'])) {
    http_response_code(400);
    echo json_encode(['error' => $respData['error']['message']]);
    exit;
}

// --- EXTRACT CONTENT ---
try {
    $assistantText = $respData['candidates'][0]['content']['parts'][0]['text'] ?? "Error: No text generated.";
    
    // Usage Metadata for Billing
    $inputTokens = $respData['usageMetadata']['promptTokenCount'] ?? 0;
    $outputTokens = $respData['usageMetadata']['candidatesTokenCount'] ?? 0;
    
    // --- LOGGING FOR GRANT REPORTING ---
    // Format: Timestamp, TeamID, Model, Input, Output
    $logLine = sprintf(
        "%s | %s | %s | In:%d | Out:%d\n",
        date('Y-m-d H:i:s'),
        $teamId,
        $model,
        $inputTokens,
        $outputTokens
    );
    file_put_contents(__DIR__ . '/gemini_usage.log', $logLine, FILE_APPEND | LOCK_EX);
    
    // Return standard format to frontend
    echo json_encode([
        'content' => [
            ['type' => 'text', 'text' => $assistantText]
        ],
        'usage' => [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse Gemini response']);
}
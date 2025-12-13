<?php
/**
 * OpenAI (ChatGPT) API Proxy for Student Interface
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

// Support either ".secrets" or ".secret" (some hosts/users prefer singular)
$secretsDirCandidates = [
    $accountRoot . '/.secrets',
    $accountRoot . '/.secret'
];

$secretsFile = null;
foreach ($secretsDirCandidates as $dir) {
    $candidate = $dir . '/openaikey.php';
    if (is_readable($candidate)) {
        $secretsFile = $candidate;
        break;
    }
}

if (!$secretsFile) {
    http_response_code(500);
    error_log("Secrets file not readable. Looked for openaikey.php in: " . implode(', ', $secretsDirCandidates));
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$OPENAI_API_KEY   = $secrets['OPENAI_API_KEY'] ?? null;
$OPENAI_ORG       = $secrets['OPENAI_ORG'] ?? null;       // optional
$OPENAI_PROJECT   = $secrets['OPENAI_PROJECT'] ?? null;   // optional

if (!$OPENAI_API_KEY) {
    http_response_code(500);
    error_log("OPENAI_API_KEY missing in secrets file: $secretsFile");
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
    'gpt-5.2',
    'gpt-5.1'
];

if (!in_array($requestData['model'], $allowedModels, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid model. Allowed: ' . implode(', ', $allowedModels)]);
    exit;
}

/**
 * Convert (legacy) Anthropic-style content arrays to OpenAI Responses API content parts.
 * Accepts:
 *  - string content
 *  - array of parts with {type:'text',text:'...'}
 *  - array of parts with {type:'image',source:{type:'base64',media_type:'image/png',data:'...'}}
 *  - OpenAI-style parts {type:'input_text',text:'...'} and {type:'input_image',image_url:'...'}
 */
function normalize_content_for_openai($content) {
    if (is_string($content)) {
        return $content;
    }

    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $part) {
        if (!is_array($part) || !isset($part['type'])) {
            continue;
        }

        $type = $part['type'];

        // Already in OpenAI Responses API format
        if ($type === 'input_text' && isset($part['text'])) {
            $parts[] = ['type' => 'input_text', 'text' => (string)$part['text']];
            continue;
        }
        if ($type === 'input_image' && isset($part['image_url'])) {
            $parts[] = ['type' => 'input_image', 'image_url' => (string)$part['image_url']];
            continue;
        }

        // Legacy "text" part
        if ($type === 'text' && isset($part['text'])) {
            $parts[] = ['type' => 'input_text', 'text' => (string)$part['text']];
            continue;
        }

        // Legacy "image" part with base64 payload
        if ($type === 'image' && isset($part['source']) && is_array($part['source'])) {
            $src = $part['source'];
            $mediaType = $src['media_type'] ?? null;
            $data = $src['data'] ?? null;
            if (is_string($mediaType) && is_string($data) && $data !== '') {
                $parts[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:' . $mediaType . ';base64,' . $data
                ];
            }
            continue;
        }
    }

    // If we found no parts, return empty string.
    if (count($parts) === 0) {
        return '';
    }

    // If it's a single text part, compress to a string for simpler payloads.
    if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'input_text') {
        return (string)($parts[0]['text'] ?? '');
    }

    return $parts;
}

/**
 * Normalize incoming message array into OpenAI Responses API `input`.
 */
function normalize_messages_for_openai($messages) {
    $out = [];
    if (!is_array($messages)) {
        return $out;
    }

    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = $msg['role'] ?? null;
        $content = $msg['content'] ?? '';
        if (!is_string($role)) {
            continue;
        }
        // Roles allowed in multi-turn input: developer/user/assistant.
        // We accept user/assistant from the browser and map "system" to developer.
        if ($role === 'system') {
            $role = 'developer';
        }
        if (!in_array($role, ['developer', 'user', 'assistant'], true)) {
            continue;
        }

        $out[] = [
            'role' => $role,
            'content' => normalize_content_for_openai($content)
        ];
    }

    return $out;
}

// Build the OpenAI Responses API request
$openaiRequest = [
    'model' => $requestData['model'],
    'input' => normalize_messages_for_openai($requestData['messages']),
    'max_output_tokens' => min((int)($requestData['max_tokens'] ?? 2048), 8192),
];

// Map legacy "system" to Responses API "instructions"
if (isset($requestData['system']) && is_string($requestData['system'])) {
    $openaiRequest['instructions'] = $requestData['system'];
}

// Optional logging
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $requestData['model'],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => is_array($requestData['messages']) ? count($requestData['messages']) : 0
];
file_put_contents(__DIR__ . '/openai_usage.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Call OpenAI
$ch = curl_init('https://api.openai.com/v1/responses');

$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY,
];

// Optional headers if using legacy user keys across multiple orgs/projects
if (is_string($OPENAI_ORG) && $OPENAI_ORG !== '') {
    $headers[] = 'OpenAI-Organization: ' . $OPENAI_ORG;
}
if (is_string($OPENAI_PROJECT) && $OPENAI_PROJECT !== '') {
    $headers[] = 'OpenAI-Project: ' . $OPENAI_PROJECT;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($openaiRequest),
    CURLOPT_HTTPHEADER => $headers,
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

// Always return JSON the browser expects.
$respData = json_decode($response, true);

if (!is_array($respData)) {
    http_response_code(502);
    echo json_encode([
        'error' => [
            'message' => 'Upstream returned non-JSON response',
            'status' => $httpCode
        ]
    ]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code($httpCode);
    // Surface OpenAI error payload as-is (it is already JSON)
    echo json_encode(['error' => $respData['error'] ?? $respData]);
    exit;
}

// Extract assistant text safely from the Responses API output array.
$textParts = [];
if (isset($respData['output']) && is_array($respData['output'])) {
    foreach ($respData['output'] as $item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') !== 'message') continue;
        if (($item['role'] ?? '') !== 'assistant') continue;
        $content = $item['content'] ?? [];
        if (!is_array($content)) continue;
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                $textParts[] = (string)$c['text'];
            }
        }
    }
}

$assistantText = implode("\n", $textParts);

// Map to the same response shape the front-end already expects.
$out = [
    'content' => [
        ['type' => 'text', 'text' => $assistantText]
    ],
    'usage' => [
        'input_tokens' => $respData['usage']['input_tokens'] ?? null,
        'output_tokens' => $respData['usage']['output_tokens'] ?? null,
    ],
    'id' => $respData['id'] ?? null,
    'model' => $respData['model'] ?? $requestData['model'],
];

http_response_code(200);
echo json_encode($out);

// (No closing PHP tag is recommended)

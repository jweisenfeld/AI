<?php
/**
 * Chat API Proxy
 * --------------
 * This endpoint loads your API key from /home2/<account>/.secrets/chatgptkey.php
 * and forwards requests to the OpenAI Responses API.
 */

declare(strict_types=1);

require_once __DIR__ . '/chat_api_utils.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$secretsPath = resolveSecretsPath(__DIR__, getenv('CHATGPT_SECRETS_PATH') ?: null);

if (!file_exists($secretsPath)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Secrets file not found. Update CHATGPT_SECRETS_PATH or place chatgptkey.php in /home2/<account>/.secrets.',
    ]);
    exit;
}

$secrets = require $secretsPath;
$apiKey = $secrets['OPENAI_API_KEY'] ?? '';
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY is missing from the secrets file.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

$messages = $input['messages'] ?? [];
$system = trim((string)($input['system'] ?? ''));
$model = $input['model'] ?? 'gpt-4.1-mini';
$temperature = (float)($input['temperature'] ?? 0.7);
$maxOutputTokens = (int)($input['max_output_tokens'] ?? 512);

$formatted = [];
if ($system !== '') {
    $formatted[] = [
        'role' => 'system',
        'content' => $system,
    ];
}

foreach ($messages as $message) {
    if (!isset($message['role'], $message['content'])) {
        continue;
    }

    $formatted[] = [
        'role' => $message['role'],
        'content' => $message['content'],
    ];
}

$payload = [
    'model' => $model,
    'temperature' => $temperature,
    'max_output_tokens' => $maxOutputTokens,
    'input' => $formatted,
];

$headers = [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Network error: ' . $curlError]);
    exit;
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    http_response_code($httpStatus);
    echo json_encode(['error' => $response]);
    exit;
}

$decoded = json_decode($response, true);
$reply = '';

if (isset($decoded['output'])) {
    foreach ($decoded['output'] as $outputItem) {
        if (($outputItem['type'] ?? '') !== 'message') {
            continue;
        }
        foreach ($outputItem['content'] as $contentPart) {
            if (($contentPart['type'] ?? '') === 'output_text') {
                $reply .= $contentPart['text'];
            }
        }
    }
}

if ($reply === '') {
    $reply = 'No response text was returned.';
}

echo json_encode(['reply' => $reply]);

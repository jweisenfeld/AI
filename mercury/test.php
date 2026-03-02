<?php
/**
 * Mercury 2 API Key Test
 * ─────────────────────
 * Upload to public_html/mercury/test.php, visit it once to verify
 * your InceptionLabs key and endpoint, then DELETE the file.
 *
 * DO NOT leave this file on the server permanently.
 */

header('Content-Type: text/plain; charset=utf-8');

// ── Load key (same path logic as api-proxy.php) ───────────────
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/inceptionkey.php';

if (!is_readable($secretsFile)) {
    die("FAIL: secrets file not readable at: $secretsFile\n");
}

$secrets = require $secretsFile;
$key = $secrets['INCEPTION_API_KEY'] ?? null;

if (!$key) {
    die("FAIL: INCEPTION_API_KEY not found in secrets file.\n");
}

$maskedKey = substr($key, 0, 7) . '...' . substr($key, -4);
echo "Key loaded : $maskedKey\n";
echo "Endpoint   : https://api.inceptionlabs.ai/v1/chat/completions\n";
echo "Model      : mercury-2\n";
echo str_repeat('-', 50) . "\n";
echo "Sending test prompt...\n\n";

// ── Minimal test request ──────────────────────────────────────
$payload = [
    'model'      => 'mercury-2',
    'messages'   => [['role' => 'user', 'content' => 'Reply with exactly: "Mercury 2 is working."']],
    'max_tokens' => 30,
];

$ch = curl_init('https://api.inceptionlabs.ai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP status : $httpCode\n\n";

if ($curlError) {
    die("CURL error: $curlError\n");
}

$decoded = json_decode($response, true);

if ($httpCode === 200 && isset($decoded['choices'][0]['message']['content'])) {
    $reply   = $decoded['choices'][0]['message']['content'];
    $ptok    = $decoded['usage']['prompt_tokens']     ?? '?';
    $ctok    = $decoded['usage']['completion_tokens'] ?? '?';
    echo "✓ SUCCESS\n";
    echo "Reply      : $reply\n";
    echo "Tokens     : {$ptok} in / {$ctok} out\n";
} else {
    echo "✗ FAILED\n";
    echo "Raw response:\n$response\n";
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Remember: delete test.php from the server after testing!\n";

<?php
/**
 * list-models.php
 *
 * Calls the Gemini ListModels API and shows which models support
 * "createCachedContent" - so we know exactly what model name to use.
 *
 * Run: https://yoursite.com/gemini3/list-models.php?secret=amentum2025
 */

header('Content-Type: text/plain; charset=utf-8');

define('CREATE_SECRET', 'amentum2025');
if (($_GET['secret'] ?? '') !== CREATE_SECRET) {
    http_response_code(403);
    die("403 Forbidden\n");
}

$accountRoot = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');

$whichKey = $_GET['key'] ?? '1';
if ($whichKey === '2') {
    $secretsFile = $accountRoot . '/.secrets/geminikey2.php';
    echo "Using ALTERNATE key: geminikey2.php\n\n";
} else {
    $secretsFile = $accountRoot . '/.secrets/amentum_geminikey.php';
    echo "Using DEFAULT key: amentum_geminikey.php\n\n";
}

require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);
echo "Key prefix: " . substr($apiKey, 0, 8) . "...\n\n";

// Fetch all models (paginate if needed)
$allModels = [];
$pageToken = null;

do {
    $url = "https://generativelanguage.googleapis.com/v1beta/models?pageSize=100&key=$apiKey";
    if ($pageToken) $url .= "&pageToken=$pageToken";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['models'])) {
        $allModels = array_merge($allModels, $data['models']);
    }
    $pageToken = $data['nextPageToken'] ?? null;
} while ($pageToken);

if (empty($allModels)) {
    echo "ERROR: No models returned. Raw response:\n$response\n";
    exit(1);
}

// ── Show models that support createCachedContent ──────────────────────────
echo "=== MODELS SUPPORTING createCachedContent ===\n";
$cacheable = [];
foreach ($allModels as $m) {
    $methods = $m['supportedGenerationMethods'] ?? [];
    if (in_array('createCachedContent', $methods)) {
        $cacheable[] = $m['name'];
        echo "  ✓ " . $m['name'] . "  (inputTokenLimit=" . ($m['inputTokenLimit'] ?? '?') . ")\n";
    }
}

if (empty($cacheable)) {
    echo "  !! NONE found - caching may not be enabled on this key/project\n";
}

// ── Show ALL models for reference ─────────────────────────────────────────
echo "\n=== ALL AVAILABLE MODELS ===\n";
foreach ($allModels as $m) {
    $methods = implode(', ', $m['supportedGenerationMethods'] ?? []);
    echo "  " . $m['name'] . "\n";
    echo "    methods: $methods\n";
}

echo "\nTotal models found: " . count($allModels) . "\n";

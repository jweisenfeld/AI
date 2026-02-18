<?php
/**
 * cache-create.php
 *
 * Run this ONCE (via browser or CLI) to upload the Pasco Municipal Code
 * to Gemini's Context Cache. Saves the cache resource name to a secrets
 * file so api-proxy.php and cache-ping.php can reference it.
 *
 * The cache TTL is set to 3600 seconds (1 hour). The cron job (cache-ping.php)
 * must run every ~45 minutes to keep it alive indefinitely.
 *
 * USAGE:
 *   Browser: https://yoursite.com/gemini3/cache-create.php?secret=YOURPASSWORD
 *   CLI:     php cache-create.php
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

// ── Simple access guard ──────────────────────────────────────────────────────
// Set a password here so this script can't be triggered by anyone on the web.
define('CREATE_SECRET', 'amentum2025');

if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== CREATE_SECRET) {
        http_response_code(403);
        die("403 Forbidden – pass ?secret=... to run this script.\n");
    }
}

// ── Paths ────────────────────────────────────────────────────────────────────
$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';
$htmlFile      = __DIR__ . '/Pasco-Municipal-Code.html';

// ── Load API key ─────────────────────────────────────────────────────────────
// Pass ?key=2 in the URL to test with geminikey2.php instead of the default key.
$whichKey = $_GET['key'] ?? '1';
if ($whichKey === '2') {
    $secretsFile = $accountRoot . '/.secrets/geminikey2.php';
    echo "Using ALTERNATE key: geminikey2.php\n";
} else {
    $secretsFile = $accountRoot . '/.secrets/amentum_geminikey.php';
    echo "Using DEFAULT key: amentum_geminikey.php\n";
}

if (!file_exists($secretsFile)) die("ERROR: API key file not found at $secretsFile\n");
require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);

// ── Load the municipal code ──────────────────────────────────────────────────
// Prefer the cleaned HTML version (junk stripped, structure preserved).
// Fall back to raw HTML if the cleaned version hasn't been generated yet.
$cleanFile = __DIR__ . '/Pasco-Municipal-Code-clean.html';
if (file_exists($cleanFile)) {
    $inputFile = $cleanFile;
    $mimeType  = 'text/html';
    echo "Using cleaned HTML version (junk stripped, structure preserved).\n";
} elseif (file_exists($htmlFile)) {
    $inputFile = $htmlFile;
    $mimeType  = 'text/html';
    echo "WARNING: Cleaned version not found. Using raw HTML (very large).\n";
    echo "         Run strip-html.php first for best results.\n";
} else {
    die("ERROR: Pasco-Municipal-Code.html not found at $htmlFile\n");
}

echo "API key loaded: " . substr($apiKey, 0, 8) . "...\n";
echo "Model         : $model\n\n";
echo "Reading Pasco Municipal Code... ";
$htmlContent = file_get_contents($inputFile);
$htmlSize    = strlen($htmlContent);
echo number_format($htmlSize) . " bytes loaded.\n";

// Gemini requires the content to be base64-encoded for inline_data uploads
$encoded = base64_encode($htmlContent);

// ── Build the create-cache request ──────────────────────────────────────────
// IMPORTANT: The caching API requires a fully-versioned model name.
// Aliases like "gemini-2.5-flash" are NOT accepted — must use dated versions.
// gemini-1.5-flash-001 has confirmed caching support on Tier 1.
$model = 'models/gemini-1.5-flash-001';

$payload = [
    'model'       => $model,
    'ttl'         => '3600s',
    'displayName' => 'Pasco-Municipal-Code',
    // systemInstruction takes NO role field — just parts
    'systemInstruction' => [
        'parts' => [[
            'text' => 'You are an expert on the Pasco Municipal Code. The following document contains the full text of the Pasco, WA Municipal Code. Use it as your primary reference when answering engineering and civic questions.'
        ]]
    ],
    'contents' => [
        [
            'role'  => 'user',
            'parts' => [
                [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data'      => $encoded
                    ]
                ]
            ]
        ]
    ]
];

// ── POST to Gemini Caching API ───────────────────────────────────────────────
$url = "https://generativelanguage.googleapis.com/v1beta/cachedContents?key=$apiKey";

echo "Uploading to Gemini Context Cache API...\n";
echo "(This may take 30-90 seconds for a 9MB document)\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    die("CURL ERROR: $curlErr\n");
}

echo "HTTP $httpCode response received.\n\n";

$result = json_decode($response, true);

if ($httpCode !== 200 || !isset($result['name'])) {
    echo "ERROR – API did not return a cache name.\n";
    echo "Full response:\n$response\n";
    exit(1);
}

// ── Save the cache name ──────────────────────────────────────────────────────
$cacheName = $result['name'];  // e.g. "cachedContents/abc123xyz"
file_put_contents($cacheNameFile, $cacheName);

echo "SUCCESS!\n";
echo "Cache name : $cacheName\n";
echo "Saved to   : $cacheNameFile\n\n";

echo "Cache details:\n";
echo "  Display name : " . ($result['displayName'] ?? '(none)') . "\n";
echo "  Model        : " . ($result['model'] ?? '(none)') . "\n";
echo "  Create time  : " . ($result['createTime'] ?? '(none)') . "\n";
echo "  Expire time  : " . ($result['expireTime'] ?? '(none)') . "\n";
echo "  Token count  : " . ($result['usageMetadata']['totalTokenCount'] ?? '(unknown)') . " tokens\n\n";

echo "NEXT STEPS:\n";
echo "  1. Set up the cron job to run cache-ping.php every 45 minutes.\n";
echo "  2. The cache will now be used automatically by api-proxy.php.\n";
echo "  3. Cached token reads are billed at a much lower rate than input tokens.\n";

<?php
/**
 * cache-create.php
 *
 * Uploads the Pasco Municipal Code to the Gemini Files API and saves the
 * returned file URI to /.secrets/gemini_cache_name.txt for use by
 * api-proxy.php and cache-ping.php.
 *
 * WHY Files API instead of Context Caching:
 *   - Context caching has a known Google bug (max_total_token_count=0) that
 *     affects some Tier 1 projects with no ETA on a fix.
 *   - The Files API works on all tiers, supports up to 2GB, and the
 *     1.36M-token document exceeds the 1M cache token limit anyway.
 *   - Files live 48 hours; we re-upload daily via cron (cache-ping.php).
 *   - Gemini reads the file server-side — students never re-send 4.4MB.
 *
 * USAGE:
 *   Browser: https://yoursite.com/gemini3/cache-create.php?secret=amentum2025
 *   CLI:     php cache-create.php
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

// ── Access guard ─────────────────────────────────────────────────────────────
define('CREATE_SECRET', 'amentum2025');
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== CREATE_SECRET) {
        http_response_code(403);
        die("403 Forbidden – pass ?secret=amentum2025 to run this script.\n");
    }
}

// ── Paths ────────────────────────────────────────────────────────────────────
$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';

// Key selection: pass ?key=2 to use geminikey2.php
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
echo "API key loaded: " . substr($apiKey, 0, 8) . "...\n\n";

// ── Choose input file ─────────────────────────────────────────────────────────
// Files API stores raw bytes with no token limit, so we use the full original
// HTML — better structure, original formatting, complete ordinance numbering.
$rawFile   = __DIR__ . '/Pasco-Municipal-Code.html';
$cleanFile = __DIR__ . '/Pasco-Municipal-Code-clean.html';

// NOTE: The full raw HTML (9MB / ~3.3M tokens) exceeds Gemini's 1M token
// context window — it uploads fine via Files API but fails at inference time.
// Use the stripped plain-text version (~875k tokens) instead.
// Re-run strip-html.php any time the source HTML is updated.
$mimeHint = __DIR__ . '/Pasco-Municipal-Code-clean.mime';

if (file_exists($cleanFile)) {
    $inputFile = $cleanFile;
    $mimeType  = file_exists($mimeHint) ? trim(file_get_contents($mimeHint)) : 'text/html';
    echo "Using cleaned file: Pasco-Municipal-Code-clean.html (mime: $mimeType)\n";
} elseif (file_exists($rawFile)) {
    $inputFile = $rawFile;
    $mimeType  = 'text/html';
    echo "WARNING: No cleaned version found — raw HTML may exceed 1M token limit.\n";
    echo "         Run strip-html.php first!\n";
} else {
    die("ERROR: No input file found. Expected Pasco-Municipal-Code-clean.html\n");
}

echo "Reading file... ";
$fileContent = file_get_contents($inputFile);
$fileSize    = strlen($fileContent);
echo number_format($fileSize) . " bytes loaded.\n\n";

// ── Upload to Gemini Files API ────────────────────────────────────────────────
// Uses multipart/related upload — metadata first, then binary content.
echo "Uploading to Gemini Files API...\n";
echo "(This may take 15-60 seconds)\n\n";

$boundary = '----GeminiBoundary' . bin2hex(random_bytes(8));
$url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=$apiKey";

// Build multipart body
$metadataJson = json_encode(['file' => ['display_name' => 'Pasco-Municipal-Code']]);

$body  = "--$boundary\r\n";
$body .= "Content-Type: application/json; charset=utf-8\r\n\r\n";
$body .= $metadataJson . "\r\n";
$body .= "--$boundary\r\n";
$body .= "Content-Type: $mimeType\r\n\r\n";
$body .= $fileContent . "\r\n";
$body .= "--$boundary--\r\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: multipart/related; boundary=$boundary",
    "X-Goog-Upload-Protocol: multipart",
    "Content-Length: " . strlen($body),
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) die("CURL ERROR: $curlErr\n");

echo "HTTP $httpCode response received.\n\n";

$result = json_decode($response, true);

if ($httpCode !== 200 || !isset($result['file']['uri'])) {
    echo "ERROR – Upload failed.\n";
    echo "Full response:\n$response\n";
    exit(1);
}

// ── Save the file URI ─────────────────────────────────────────────────────────
$fileUri   = $result['file']['uri'];
$fileState = $result['file']['state'] ?? 'UNKNOWN';
$fileName  = $result['file']['name'] ?? '(unknown)';

file_put_contents($cacheNameFile, $fileUri);

echo "SUCCESS!\n";
echo "File URI   : $fileUri\n";
echo "File name  : $fileName\n";
echo "State      : $fileState\n";
echo "Expires    : " . ($result['file']['expirationTime'] ?? '48 hours from now') . "\n";
echo "Size       : " . ($result['file']['sizeBytes'] ?? number_format($fileSize)) . " bytes\n";
echo "Saved to   : $cacheNameFile\n\n";

if ($fileState !== 'ACTIVE') {
    echo "NOTE: File state is '$fileState' (not yet ACTIVE).\n";
    echo "      For large files this is normal — state becomes ACTIVE within seconds.\n";
    echo "      api-proxy.php will use it once it's ACTIVE.\n\n";
}

echo "NEXT STEPS:\n";
echo "  1. Cron job: set cache-ping.php to run daily at 3am.\n";
echo "     Command: /usr/local/bin/php /home2/fikrttmy/public_html/gemini3/cache-ping.php >> /home2/fikrttmy/public_html/gemini3/cache-ping.log 2>&1\n";
echo "     Schedule: Minute=0, Hour=3, Day=*, Month=*, Weekday=*\n";
echo "  2. api-proxy.php already reads this URI automatically.\n";
echo "  3. Test a student chat and check gemini_usage.log for FILE_URI flag.\n";

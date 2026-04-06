<?php
/**
 * lotf1/cache-create.php
 *
 * Uploads Lord-of-the-Flies.pdf to the Gemini Files API and saves the
 * returned file URI to /.secrets/lotf1_cache_name.txt for use by
 * api-proxy.php and cache-ping.php.
 *
 * USAGE:
 *   Browser: https://yoursite.com/lotf1/cache-create.php?secret=amentum2025
 *   CLI:     php cache-create.php
 *
 * NEXT STEPS after first run:
 *   1. Set a daily cron to run cache-ping.php (Files API URIs expire after 48h)
 *   2. Test a student query and check gemini_usage.log for FILE_URI or EXPLICIT_CACHE flag
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
$cacheNameFile = $accountRoot . '/.secrets/lotf1_cache_name.txt';
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';

if (!file_exists($secretsFile)) die("ERROR: API key file not found at $secretsFile\n");
require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);
echo "API key loaded: " . substr($apiKey, 0, 8) . "...\n\n";

// ── Input file ────────────────────────────────────────────────────────────────
$pdfFile  = __DIR__ . '/Lord-of-the-Flies.pdf';
$mimeType = 'application/pdf';

if (!file_exists($pdfFile)) {
    die("ERROR: PDF not found at $pdfFile\n"
      . "       Place Lord-of-the-Flies.pdf in the lotf1/ folder and re-run.\n");
}

echo "Reading PDF... ";
$fileContent = file_get_contents($pdfFile);
$fileSize    = strlen($fileContent);
echo number_format($fileSize) . " bytes loaded.\n\n";

// ── Upload to Gemini Files API ────────────────────────────────────────────────
echo "Uploading to Gemini Files API...\n";
echo "(This may take 15-60 seconds)\n\n";

$boundary     = '----GeminiBoundary' . bin2hex(random_bytes(8));
$url          = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=$apiKey";
$metadataJson = json_encode(['file' => ['display_name' => 'Lord-of-the-Flies']]);

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
$fileUri         = $result['file']['uri'];
$fileState       = $result['file']['state'] ?? 'UNKNOWN';
$fileName        = $result['file']['name'] ?? '(unknown)';
$expirationIso   = $result['file']['expirationTime'] ?? null;
$expireTimestamp = $expirationIso ? strtotime($expirationIso) : (time() + 47 * 3600);

file_put_contents($cacheNameFile, $fileUri);
file_put_contents($cacheNameFile . '.expires', (string)$expireTimestamp);

echo "SUCCESS!\n";
echo "File URI   : $fileUri\n";
echo "File name  : $fileName\n";
echo "State      : $fileState\n";
echo "Expires    : " . ($expirationIso ?? '48 hours from now') . " (unix: $expireTimestamp)\n";
echo "Size       : " . ($result['file']['sizeBytes'] ?? number_format($fileSize)) . " bytes\n";
echo "Saved to   : $cacheNameFile\n\n";

if ($fileState !== 'ACTIVE') {
    echo "NOTE: File state is '$fileState' (not yet ACTIVE).\n";
    echo "      For large files this is normal — state becomes ACTIVE within seconds.\n\n";
}

echo "NEXT STEPS:\n";
echo "  1. Set up daily cron to keep the Files API URI fresh (48h TTL):\n";
echo "     Command: /usr/local/bin/php /home2/fikrttmy/public_html/lotf1/cache-ping.php >> /home2/fikrttmy/public_html/lotf1/cache-ping.log 2>&1\n";
echo "     Schedule: Minute=0, Hour=3, Day=*, Month=*, Weekday=*\n";
echo "  2. Test a student query and check gemini_usage.log for FILE_URI or EXPLICIT_CACHE flag.\n";

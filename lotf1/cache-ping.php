<?php
/**
 * lotf1/cache-ping.php
 *
 * Daily cron job that keeps Lord-of-the-Flies.pdf available in the
 * Gemini Files API. Files expire after 48 hours; run this daily at 3am
 * to re-upload before expiry (24hr interval = comfortable safety margin).
 *
 * Cron settings (cPanel):
 *   Minute:  0
 *   Hour:    3
 *   Day:     *
 *   Month:   *
 *   Weekday: *
 *   Command: /usr/local/bin/php /home2/fikrttmy/public_html/lotf1/cache-ping.php >> /home2/fikrttmy/public_html/lotf1/cache-ping.log 2>&1
 */

set_time_limit(120);

$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '/home2/fikrttmy';
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$cacheNameFile = $accountRoot . '/.secrets/lotf1_cache_name.txt';
$logFile       = __DIR__ . '/cache-ping.log';
$pdfFile       = __DIR__ . '/Lord-of-the-Flies.pdf';
$mimeType      = 'application/pdf';

$timestamp = date('Y-m-d H:i:s');

function log_msg($msg) {
    global $logFile, $timestamp;
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

if (!file_exists($secretsFile)) {
    log_msg("ERROR: API key file not found at $secretsFile");
    exit(1);
}
require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);

if (!file_exists($pdfFile)) {
    log_msg("ERROR: Lord-of-the-Flies.pdf not found at $pdfFile");
    exit(1);
}

log_msg("Starting daily re-upload of Lord-of-the-Flies.pdf...");

$fileContent = file_get_contents($pdfFile);
$fileSize    = strlen($fileContent);
log_msg("File loaded: " . number_format($fileSize) . " bytes");

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
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    log_msg("CURL ERROR: $curlErr");
    exit(1);
}

$result = json_decode($response, true);

if ($httpCode !== 200 || !isset($result['file']['uri'])) {
    log_msg("ERROR – HTTP $httpCode | Response: " . substr($response, 0, 300));
    exit(1);
}

$newUri = $result['file']['uri'];
$expiry = $result['file']['expirationTime'] ?? '~48 hours';

// Delete old file from Gemini to avoid accumulation
$oldUri = trim(file_get_contents($cacheNameFile) ?: '');
if (!empty($oldUri)) {
    $oldName = preg_replace('#.*/v1beta/#', '', $oldUri);
    if ($oldName && $oldName !== $oldUri) {
        $deleteUrl = "https://generativelanguage.googleapis.com/v1beta/{$oldName}?key=$apiKey";
        $dch = curl_init($deleteUrl);
        curl_setopt($dch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($dch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($dch, CURLOPT_TIMEOUT, 15);
        curl_exec($dch);
        curl_close($dch);
    }
}

file_put_contents($cacheNameFile, $newUri);
$expireTimestamp = ($expiry !== '~48 hours') ? strtotime($expiry) : (time() + 47 * 3600);
file_put_contents($cacheNameFile . '.expires', (string)$expireTimestamp);

log_msg("OK – PDF re-uploaded successfully.");
log_msg("     New URI    : $newUri");
log_msg("     Expires    : $expiry (unix: $expireTimestamp)");
log_msg("     Saved to   : $cacheNameFile");

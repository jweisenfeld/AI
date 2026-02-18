<?php
/**
 * cache-ping.php
 *
 * Daily cron job that keeps the Pasco Municipal Code available in the
 * Gemini Files API. Files expire after 48 hours; this runs at 3am daily
 * to re-upload before expiry (24hr interval = comfortable safety margin).
 *
 * Strategy: always re-upload (simpler than checking expiry, and a 4.4MB
 * text upload completes in seconds — not worth the complexity of polling).
 *
 * Bluehost cron settings:
 *   Minute:  0
 *   Hour:    3
 *   Day:     *
 *   Month:   *
 *   Weekday: *
 *   Command: /usr/local/bin/php /home2/fikrttmy/public_html/gemini3/cache-ping.php >> /home2/fikrttmy/public_html/gemini3/cache-ping.log 2>&1
 */

set_time_limit(120);

// ── Paths ────────────────────────────────────────────────────────────────────
$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';
$logFile       = __DIR__ . '/cache-ping.log';

$timestamp = date('Y-m-d H:i:s');

function log_msg($msg) {
    global $logFile, $timestamp;
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// ── Load API key ─────────────────────────────────────────────────────────────
if (!file_exists($secretsFile)) {
    log_msg("ERROR: API key file not found at $secretsFile");
    exit(1);
}
require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);

// ── Choose input file ─────────────────────────────────────────────────────────
$cleanFile = __DIR__ . '/Pasco-Municipal-Code-clean.html';
$rawFile   = __DIR__ . '/Pasco-Municipal-Code.html';

if (file_exists($cleanFile)) {
    $inputFile = $cleanFile;
    $mimeType  = 'text/html';
} elseif (file_exists($rawFile)) {
    $inputFile = $rawFile;
    $mimeType  = 'text/html';
} else {
    log_msg("ERROR: No municipal code file found. Expected Pasco-Municipal-Code-clean.html");
    exit(1);
}

// ── Upload to Files API ───────────────────────────────────────────────────────
log_msg("Starting daily re-upload of Pasco Municipal Code...");

$fileContent = file_get_contents($inputFile);
$fileSize    = strlen($fileContent);
log_msg("File loaded: " . number_format($fileSize) . " bytes from " . basename($inputFile));

$boundary = '----GeminiBoundary' . bin2hex(random_bytes(8));
$url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=$apiKey";

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

// ── Save new URI ──────────────────────────────────────────────────────────────
$newUri   = $result['file']['uri'];
$expiry   = $result['file']['expirationTime'] ?? '~48 hours';

// Delete old file from Gemini to avoid accumulation (best-effort, ignore errors)
$oldUri = trim(file_get_contents($cacheNameFile) ?: '');
if (!empty($oldUri)) {
    // Extract file name from URI for the DELETE call
    // URI format: https://generativelanguage.googleapis.com/v1beta/files/FILE_ID
    $oldName = preg_replace('#.*/v1beta/#', '', $oldUri); // → "files/FILE_ID"
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

log_msg("OK – File re-uploaded successfully.");
log_msg("     New URI    : $newUri");
log_msg("     Expires    : $expiry");
log_msg("     Saved to   : $cacheNameFile");

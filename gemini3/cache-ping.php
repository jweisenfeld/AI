<?php
/**
 * cache-ping.php
 *
 * Called by the Bluehost cron job every 45 minutes to PATCH the cache TTL,
 * keeping the Gemini Context Cache alive indefinitely.
 *
 * Gemini caches expire after their TTL (max 1 hour). This script resets the
 * TTL back to 3600s before it can expire.
 *
 * Cron schedule: every 45 minutes
 *   Minute: 0,45   Hour: *   Day: *   Month: *   Weekday: *
 *
 * Bluehost command:
 *   /usr/local/bin/php /home2/fikrttmy/public_html/gemini3/cache-ping.php >> /home2/fikrttmy/public_html/gemini3/cache-ping.log 2>&1
 */

set_time_limit(60);

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
    // Also echo for cron email / CLI output
    echo $line;
}

// ── Load cache name ──────────────────────────────────────────────────────────
if (!file_exists($cacheNameFile)) {
    log_msg("ERROR: Cache name file not found at $cacheNameFile");
    log_msg("       Run cache-create.php first to create the cache.");
    exit(1);
}
$cacheName = trim(file_get_contents($cacheNameFile));
if (empty($cacheName)) {
    log_msg("ERROR: Cache name file is empty. Re-run cache-create.php.");
    exit(1);
}

// ── Load API key ─────────────────────────────────────────────────────────────
if (!file_exists($secretsFile)) {
    log_msg("ERROR: API key file not found at $secretsFile");
    exit(1);
}
require_once($secretsFile);
$apiKey = trim($GEMINI_API_KEY);

// ── PATCH the cache to reset TTL ─────────────────────────────────────────────
// The PATCH endpoint updates the expireTime by providing a new ttl.
$url = "https://generativelanguage.googleapis.com/v1beta/{$cacheName}?key=$apiKey";

$payload = ['ttl' => '3600s'];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-HTTP-Method-Override: PATCH'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    log_msg("CURL ERROR: $curlErr");
    exit(1);
}

$result = json_decode($response, true);

if ($httpCode === 200 && isset($result['name'])) {
    $newExpiry = $result['expireTime'] ?? 'unknown';
    log_msg("OK – Cache refreshed. New expiry: $newExpiry | Cache: $cacheName");
} elseif ($httpCode === 404) {
    // Cache was deleted or expired before we could ping it — need to recreate
    log_msg("WARN – Cache not found (404). Cache may have expired.");
    log_msg("       Attempting to clear saved cache name so next request triggers rebuild.");
    // Clear the stale cache name so api-proxy falls back gracefully
    file_put_contents($cacheNameFile, '');
    log_msg("       Cleared $cacheNameFile — run cache-create.php to rebuild the cache.");
    exit(2);
} else {
    log_msg("ERROR – HTTP $httpCode | Response: " . substr($response, 0, 500));
    exit(1);
}

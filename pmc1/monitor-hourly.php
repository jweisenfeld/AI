<?php
/**
 * monitor-hourly.php
 *
 * Hourly health monitor for pmc1.
 * - Checks recent successful cache-ping entry
 * - Checks Files API URI expiry horizon
 * - Optionally runs a synthetic chat probe via api-proxy debug route
 * - Sends email summary (PASS/FAIL) using /.secrets/smtp_credentials.php
 *
 * Recommended cron:
 *   0 * * * * /usr/bin/flock -n /tmp/pmc1-monitor-hourly.lock /usr/local/bin/php /home2/fikrttmy/public_html/pmc1/monitor-hourly.php >> /home2/fikrttmy/public_html/pmc1/monitor-hourly.log 2>&1
 */

set_time_limit(120);
date_default_timezone_set('UTC');

define('MONITOR_SECRET', 'amentum2025');
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== MONITOR_SECRET) {
        http_response_code(403);
        die("403 Forbidden\n");
    }
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if (!is_string($docRoot) || trim($docRoot) === '') {
    $docRoot = '/home2/fikrttmy/public_html';
}
$accountRoot = dirname($docRoot);
$secretsFile = $accountRoot . '/.secrets/smtp_credentials.php';
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';
$expiryFile = $cacheNameFile . '.expires';
$pingLog = __DIR__ . '/cache-ping.log';
$monitorLog = __DIR__ . '/monitor-hourly.log';
$monitorBaseUrlFile = $accountRoot . '/.secrets/monitor_base_url.txt';
$selfLockFile = sys_get_temp_dir() . '/pmc1-monitor-hourly.lock';

$selfLockHandle = fopen($selfLockFile, 'c');
if ($selfLockHandle === false) {
    http_response_code(500);
    die("Could not open monitor lock file\n");
}
if (!flock($selfLockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . gmdate('Y-m-d H:i:s') . " UTC] Another monitor instance is already running; exiting.\n";
    exit(0);
}

function monitor_log(string $msg): void {
    global $monitorLog;
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] $msg\n";
    file_put_contents($monitorLog, $line, FILE_APPEND);
    echo $line;
}

function parse_last_ok_ping_ts(string $pingLog): ?int {
    if (!file_exists($pingLog)) return null;
    $lines = @file($pingLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return null;

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        if (strpos($line, 'OK') === false) continue;
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            $ts = strtotime($m[1] . ' UTC');
            if ($ts !== false) return $ts;
        }
    }
    return null;
}

function smtp_expect($fp, array $codes): string {
    $line = '';
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        if ($line === false) break;
        if (preg_match('/^\d{3} /', $line)) break;
    }
    $code = (int)substr((string)$line, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException("SMTP unexpected response: " . trim((string)$line));
    }
    return (string)$line;
}

function smtp_send_line($fp, string $cmd): void {
    fwrite($fp, $cmd . "\r\n");
}

function send_smtp_mail(array $smtp, string $to, string $subject, string $body, ?string $cc = null): void {
    $host = $smtp['host'];
    $port = (int)$smtp['port'];
    $user = $smtp['user'];
    $pass = $smtp['pass'];
    $from = $smtp['from'];
    $fromName = $smtp['from_name'];

    $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 15);

    smtp_expect($fp, [220]);
    smtp_send_line($fp, 'EHLO pmc1.local');
    smtp_expect($fp, [250]);

    smtp_send_line($fp, 'STARTTLS');
    smtp_expect($fp, [220]);
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('SMTP STARTTLS failed');
    }

    smtp_send_line($fp, 'EHLO pmc1.local');
    smtp_expect($fp, [250]);

    smtp_send_line($fp, 'AUTH LOGIN');
    smtp_expect($fp, [334]);
    smtp_send_line($fp, base64_encode($user));
    smtp_expect($fp, [334]);
    smtp_send_line($fp, base64_encode($pass));
    smtp_expect($fp, [235]);

    smtp_send_line($fp, "MAIL FROM:<$from>");
    smtp_expect($fp, [250]);
    smtp_send_line($fp, "RCPT TO:<$to>");
    smtp_expect($fp, [250, 251]);
    if (!empty($cc)) {
        smtp_send_line($fp, "RCPT TO:<$cc>");
        smtp_expect($fp, [250, 251]);
    }

    smtp_send_line($fp, 'DATA');
    smtp_expect($fp, [354]);

    $headers = [];
    $headers[] = 'From: ' . $fromName . " <{$from}>";
    $headers[] = "To: <{$to}>";
    if (!empty($cc)) $headers[] = "Cc: <{$cc}>";
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'Date: ' . gmdate('r');
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";
    smtp_send_line($fp, $payload);
    smtp_expect($fp, [250]);

    smtp_send_line($fp, 'QUIT');
    fclose($fp);
}

function run_synthetic_probe(?string $baseUrl): array {
    if (empty($baseUrl)) {
        return ['ran' => false, 'ok' => true, 'detail' => 'Skipped (no monitor_base_url configured)'];
    }

    $url = rtrim($baseUrl, '/') . '/api-proxy.php';
    $payload = json_encode([
        'action' => 'debug_chat',
        'secret' => MONITOR_SECRET,
        'model' => 'gemini-2.5-flash',
        'message' => 'Reply with exactly: OK'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ran' => true, 'ok' => false, 'detail' => "cURL error $errno: $err"];
    }
    if ($code !== 200) {
        return ['ran' => true, 'ok' => false, 'detail' => "HTTP $code"];
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data['raw_body'])) {
        return ['ran' => true, 'ok' => false, 'detail' => 'No raw_body in debug response'];
    }

    $hasText = strpos($data['raw_body'], '"text"') !== false;
    return [
        'ran' => true,
        'ok' => $hasText,
        'detail' => $hasText ? 'OK' : 'No text chunk found in stream body',
    ];
}

// ── Health checks ─────────────────────────────────────────────────────────────
$issues = [];
$notes = [];
$now = time();

$lastOkTs = parse_last_ok_ping_ts($pingLog);
if ($lastOkTs === null) {
    $issues[] = 'No successful cache-ping entry found in cache-ping.log';
} else {
    $hoursAgo = round(($now - $lastOkTs) / 3600, 2);
    $notes[] = "Last successful cache-ping: {$hoursAgo}h ago (" . gmdate('Y-m-d H:i:s', $lastOkTs) . " UTC)";
    if ($hoursAgo > 30) {
        $issues[] = "Last successful cache-ping is stale ({$hoursAgo}h ago)";
    }
}

if (!file_exists($expiryFile)) {
    $issues[] = 'Missing gemini cache expiry file';
} else {
    $expireTs = (int)trim((string)file_get_contents($expiryFile));
    $hoursLeft = round(($expireTs - $now) / 3600, 2);
    $notes[] = "Files API URI expires in {$hoursLeft}h (" . gmdate('Y-m-d H:i:s', $expireTs) . " UTC)";
    if ($hoursLeft < 6) {
        $issues[] = "Files API URI expiry horizon too short ({$hoursLeft}h left)";
    }
}

$baseUrl = null;
if (file_exists($monitorBaseUrlFile)) {
    $baseUrl = trim((string)file_get_contents($monitorBaseUrlFile));
}
$probe = run_synthetic_probe($baseUrl);
$notes[] = 'Synthetic probe: ' . $probe['detail'];
if (!$probe['ok']) $issues[] = 'Synthetic probe failed: ' . $probe['detail'];

$status = empty($issues) ? 'PASS' : 'FAIL';
$subject = "[pmc1 monitor] {$status} @ " . gmdate('Y-m-d H:i') . ' UTC';
$bodyLines = [];
$bodyLines[] = "pmc1 hourly monitor status: {$status}";
$bodyLines[] = 'Timestamp: ' . gmdate('Y-m-d H:i:s') . ' UTC';
$bodyLines[] = '';
$bodyLines[] = 'Checks:';
foreach ($notes as $n) $bodyLines[] = " - {$n}";
if (!empty($issues)) {
    $bodyLines[] = '';
    $bodyLines[] = 'Failures:';
    foreach ($issues as $i) $bodyLines[] = " - {$i}";
}
$body = implode("\n", $bodyLines) . "\n";

if (!file_exists($secretsFile)) {
    monitor_log('ERROR: SMTP credentials file missing at ' . $secretsFile);
    monitor_log('Status: ' . $status . ' (email not sent)');
    exit(1);
}
require_once $secretsFile;

$smtp = [
    'host' => $SMTP_HOST ?? '',
    'port' => $SMTP_PORT ?? 587,
    'user' => $SMTP_USER ?? '',
    'pass' => $SMTP_PASS ?? '',
    'from' => $SMTP_FROM ?? '',
    'from_name' => $SMTP_FROM_NAME ?? 'pmc1 monitor',
];
$to = 'jweisenfeld@psd1.org';
$cc = $TEACHER_CC ?? null;

try {
    send_smtp_mail($smtp, $to, $subject, $body, $cc);
    monitor_log("Email sent to {$to}" . ($cc ? " (cc {$cc})" : '') . " with status {$status}");
} catch (Throwable $e) {
    monitor_log('ERROR sending email: ' . $e->getMessage());
    exit(1);
}

monitor_log('Monitor completed with status: ' . $status);
exit($status === 'PASS' ? 0 : 1);

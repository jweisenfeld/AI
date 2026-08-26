<?php
// Refer V1 — appends one click to a flat log file. Student is optional.
header('Content-Type: application/json');

$logFile = __DIR__ . '/referral-log.csv';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$type = isset($data['type']) ? trim($data['type']) : '';
if ($type !== 'Major' && $type !== 'Minor') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'type must be Major or Minor']);
    exit;
}

$category    = isset($data['category']) ? trim($data['category']) : '';
$teacher     = isset($data['teacher']) ? trim($data['teacher']) : 'Unknown';
$note        = isset($data['note']) ? trim($data['note']) : '';
$gaps        = isset($data['gaps']) ? trim($data['gaps']) : '';
$student     = isset($data['student']) ? trim($data['student']) : '';
$clientTime  = isset($data['client_time']) ? trim($data['client_time']) : '';

// Server clock is the source of truth for the timestamp.
$timestamp = date('Y-m-d H:i:s');

$isNewFile = !file_exists($logFile);
$fh = fopen($logFile, 'a');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'could not open log file']);
    exit;
}

if (flock($fh, LOCK_EX)) {
    if ($isNewFile) {
        fputcsv($fh, ['timestamp', 'teacher', 'type', 'category', 'gaps', 'note', 'client_time', 'ip', 'student'], ',', '"', '\\');
    }
    fputcsv($fh, [
        $timestamp,
        $teacher,
        $type,
        $category,
        $gaps,
        $note,
        $clientTime,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $student
    ], ',', '"', '\\');
    flock($fh, LOCK_UN);
}
fclose($fh);

echo json_encode(['status' => 'ok', 'timestamp' => $timestamp]);

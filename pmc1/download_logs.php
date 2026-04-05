<?php
$logDir = __DIR__ . '/student_logs';
$zipName = 'student_logs_' . date('Ymd_His') . '.zip';

$files = glob($logDir . '/*.txt');

if (empty($files)) {
    http_response_code(404);
    exit('No log files found.');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'logs_');

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create zip file.');
}

foreach ($files as $file) {
    $zip->addFile($file, basename($file));
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache');

readfile($tmpFile);
unlink($tmpFile);
exit;

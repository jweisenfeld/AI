<?php
// Refer V1 — server-side student autosuggest. The full roster never ships
// to the browser; only the matches for the current query do.
header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$csvFile = __DIR__ . '/Students_List.csv';
$matches = [];

if (file_exists($csvFile)) {
    $fh = fopen($csvFile, 'r');
    $header = fgetcsv($fh, 0, ',', '"', '\\');
    $headerCount = count($header);
    $needle = strtolower($q);

    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($r) < $headerCount) continue;
        $row = array_combine($header, array_slice($r, 0, $headerCount));

        $hay = strtolower(implode(' ', [
            $row['FirstName'] ?? '',
            $row['LastName'] ?? '',
            $row['Email'] ?? '',
            $row['StudentID'] ?? '',
        ]));

        if (strpos($hay, $needle) !== false) {
            $matches[] = [
                'firstName' => $row['FirstName'] ?? '',
                'lastName'  => $row['LastName'] ?? '',
                'email'     => $row['Email'] ?? '',
                'studentId' => $row['StudentID'] ?? '',
                'grade'     => $row['Grade'] ?? '',
            ];
            if (count($matches) >= 15) break;
        }
    }
    fclose($fh);
}

echo json_encode($matches);

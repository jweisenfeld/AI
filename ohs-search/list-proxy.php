<?php
/**
 * OHS Memory — Document List Proxy
 * Returns all ingested documents for the archive browser tab.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Secrets ────────────────────────────────────────────────────────────────────
$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}
$secrets = require $secretsFile;

$SUPABASE_URL      = $secrets['SUPABASE_URL']      ?? null;
$SUPABASE_ANON_KEY = $secrets['SUPABASE_ANON_KEY'] ?? null;

if (!$SUPABASE_URL || !$SUPABASE_ANON_KEY) {
    http_response_code(500);
    error_log("Missing required keys in $secretsFile");
    echo json_encode(['error' => 'Server configuration error (key missing).']);
    exit;
}

$subject  = trim($_GET['subject']  ?? '') ?: null;
$year     = trim($_GET['year']     ?? '') ?: null;
$doc_type = trim($_GET['doc_type'] ?? '') ?: null;

$params = [];
if ($subject)  $params['filter_subject']  = $subject;
if ($year)     $params['filter_year']     = $year;
if ($doc_type) $params['filter_doc_type'] = $doc_type;

$url = $SUPABASE_URL . '/rest/v1/rpc/list_ohs_documents';
if (!empty($params)) {
    $url .= '?' . http_build_query($params);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'apikey: '         . $SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . $SUPABASE_ANON_KEY,
    ],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    http_response_code(500);
    echo json_encode(['error' => "Supabase error (HTTP $http_code): $response"]);
    exit;
}

$docs = json_decode($response, true) ?? [];
echo json_encode(['count' => count($docs), 'documents' => $docs]);

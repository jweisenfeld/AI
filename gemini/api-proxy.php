<?php
/**
 * OHS Gemini Proxy - Enhanced Debug Version
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsFile = $accountRoot . '/.secrets/geminikey.php';
$studentFile = $accountRoot . '/.secrets/student_roster.csv'; 

function send_error($message, $details = null) {
    echo json_encode(['error' => $message, 'details' => $details]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) send_error("No JSON data received by PHP.");

// --- LOGIN ROUTE ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    if (!file_exists($studentFile)) send_error("Roster missing", "Path: $studentFile");
    $handle = fopen($studentFile, "r");
    fgetcsv($handle); 
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if ($row[2] == $data['student_id'] && $row[6] == $data['password']) {
            echo json_encode(['success' => true, 'student_name' => $row[9]]);
            fclose($handle); exit;
        }
    }
    fclose($handle);
    send_error("Invalid credentials.");
}

// --- CHAT ROUTE ---
if (!file_exists($secretsFile)) send_error("API Key file missing.");
require_once($secretsFile); // Defines $GEMINI_API_KEY

$model = $data['model'] ?? 'gemini-1.5-pro';
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$GEMINI_API_KEY";

// Format Gemini Payload
$contents = [];
foreach ($data['messages'] as $m) {
    $role = ($m['role'] === 'assistant') ? 'model' : 'user';
    // Handle complex content (text + images) or simple strings
    if (is_string($m['content'])) {
        $parts = [["text" => $m['content']]];
    } else {
        $parts = array_map(function($p) {
            if (isset($p['text'])) return ["text" => $p['text']];
            if (isset($p['source'])) return ["inlineData" => ["mimeType" => $p['source']['media_type'], "data" => $p['source']['data']]];
            return null;
        }, $m['content']);
    }
    $contents[] = ["role" => $role, "parts" => array_filter($parts)];
}

$payload = [
    "contents" => $contents,
    "systemInstruction" => ["parts" => [["text" => $data['system']]]]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) send_error("CURL Error", $err);

// This is the important part: forward the actual Google response
echo $response;
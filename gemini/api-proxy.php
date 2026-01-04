<?php
/**
 * Gemini API Proxy - OHS Infrastructure Project
 * Handles Authentication and Gemini Pro Requests
 */

set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

// --- CONFIGURATION ---
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsFile = $accountRoot . '/.secrets/geminikey.php';
$studentFile = $accountRoot . '/.secrets/student_roster.csv'; 

// --- HELPER: ERROR RESPONSE ---
function send_error($message, $code = 500, $details = null) {
    http_response_code($code);
    echo json_encode(['error' => $message, 'details' => $details]);
    exit;
}

// Read Incoming JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) send_error("No data received", 400);

// --- ROUTE 1: LOGIN VERIFICATION ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    $id = $data['student_id'] ?? '';
    $pass = $data['password'] ?? '';
    
    if (!file_exists($studentFile)) send_error("Roster file missing", 500, "Looked in: $studentFile");

    if (($handle = fopen($studentFile, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // CSV Indices: ID=2, Password=6, Name=9
            if ($row[2] == $id && $row[6] == $pass) {
                echo json_encode(['success' => true, 'student_name' => $row[9]]);
                fclose($handle);
                exit;
            }
        }
        fclose($handle);
    }
    send_error("Invalid Credentials", 401);
}

// --- ROUTE 2: GEMINI PROXY ---
if (!file_exists($secretsFile)) send_error("API Key file missing", 500);
require_once($secretsFile); // Should define $GEMINI_API_KEY

$model = $data['model'] ?? 'gemini-1.5-pro';
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$GEMINI_API_KEY";

// Prepare Gemini Payload
$geminiPayload = [
    "systemInstruction" => [
        "parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]
    ],
    "contents" => []
];

foreach ($data['messages'] as $m) {
    $role = ($m['role'] === 'assistant') ? 'model' : 'user';
    $parts = [];
    
    if (is_string($m['content'])) {
        $parts[] = ["text" => $m['content']];
    } else {
        foreach ($m['content'] as $p) {
            if ($p['type'] === 'text') $parts[] = ["text" => $p['text']];
            if ($p['type'] === 'image') {
                $parts[] = [
                    "inlineData" => [
                        "mimeType" => $p['source']['media_type'],
                        "data" => $p['source']['data']
                    ]
                ];
            }
        }
    }
    $geminiPayload['contents'][] = ["role" => $role, "parts" => $parts];
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($geminiPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) send_error("CURL Error", 500, curl_error($ch));
curl_close($ch);

// LOGGING
$logName = $data['student_name'] ?? 'unknown';
$logLine = date('Y-m-d H:i:s') . " | $logName | HTTP $httpCode\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;
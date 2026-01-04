<?php
/**
 * OHS Gemini Proxy - Stable Production Version
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

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) send_error("No JSON received.");

// --- 1. LOGIN ROUTE ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    if (!file_exists($studentFile)) send_error("Roster missing.");
    $handle = fopen($studentFile, "r");
    fgetcsv($handle); 
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (trim($row[2]) == trim($data['student_id']) && trim($row[6]) == trim($data['password'])) {
            echo json_encode(['success' => true, 'student_name' => $row[9]]);
            fclose($handle); exit;
        }
    }
    fclose($handle);
    send_error("Invalid credentials.");
}

// --- 2. CHAT ROUTE ---
if (!file_exists($secretsFile)) send_error("API Key file missing.");
require_once($secretsFile); 

// Model Mapping to prevent brittleness
$modelMap = [
    "gemini-pro" => "gemini-1.5-pro-002",
    "gemini-flash" => "gemini-1.5-flash-002"
];
$requested = $data['model'] ?? 'gemini-pro';
$actualModel = $modelMap[$requested] ?? "gemini-1.5-pro-002";

// Use v1beta for systemInstruction support, but with the specific model ID
$url = "https://generativelanguage.googleapis.com/v1beta/models/$actualModel:generateContent?key=" . trim($GEMINI_API_KEY);

$contents = [];
$lastPrompt = "";
foreach ($data['messages'] as $m) {
    $role = ($m['role'] === 'assistant') ? 'model' : 'user';
    $parts = [];
    if (is_string($m['content'])) {
        $parts[] = ["text" => $m['content']];
        if ($role === 'user') $lastPrompt = $m['content'];
    } else {
        foreach ($m['content'] as $p) {
            if (isset($p['text'])) { $parts[] = ["text" => $p['text']]; if($role==='user')$lastPrompt=$p['text']; }
            if (isset($p['source'])) $parts[] = ["inlineData" => ["mimeType" => $p['source']['media_type'], "data" => $p['source']['data']]];
        }
    }
    $contents[] = ["role" => $role, "parts" => $parts];
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
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 3. LOGGING (Fixed the undefined $model variable) ---
$usage = json_decode($response, true)['usageMetadata'] ?? ['promptTokenCount' => 0, 'candidatesTokenCount' => 0];
$studentName = $data['student_name'] ?? 'Unknown';
$cleanPrompt = str_replace(["\r", "\n", "|"], " ", substr($lastPrompt, 0, 100));
$logLine = date('Y-m-d H:i:s') . " | $studentName | $actualModel | In:{$usage['promptTokenCount']} | Out:{$usage['candidatesTokenCount']} | PROMPT: $cleanPrompt\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;
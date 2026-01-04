<?php
/**
 * FINAL API PROXY - Orion High School Engineering Project
 * Handles: Student Authentication, Gemini 1.5 Pro Multimodal, and Usage Logging
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

// --- 1. CONFIGURATION ---
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsFile = $accountRoot . '/.secrets/geminikey.php';
$studentFile = $accountRoot . '/.secrets/student_roster.csv'; 

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!$data) send_error("No valid JSON received.");

// --- 2. AUTHENTICATION ROUTE ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    if (!file_exists($studentFile)) send_error("Roster file not found.", "Path: $studentFile");
    
    $found = false;
    if (($handle = fopen($studentFile, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header row
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Student ID = index 2 | Password = index 6 | Full Name = index 9
            if (trim($row[2]) == trim($data['student_id']) && trim($row[6]) == trim($data['password'])) {
                echo json_encode(['success' => true, 'student_name' => $row[9]]);
                $found = true;
                break;
            }
        }
        fclose($handle);
    }
    if (!$found) send_error("Invalid Student ID or Password.");
    exit;
}

// --- 3. GEMINI PROXY ROUTE ---
if (!file_exists($secretsFile)) send_error("API Key file missing.");
require_once($secretsFile); // Defines $GEMINI_API_KEY

if (!isset($GEMINI_API_KEY) || empty($GEMINI_API_KEY)) {
    send_error("API Key is undefined in geminikey.php");
}

// Inside api-proxy.php
// Map "friendly" names to "technical" Google names
$modelMap = [
    "gemini-pro" => "gemini-1.5-pro-002", // This is the stable production version
    "gemini-flash" => "gemini-1.5-flash-002"
];

$requestedModel = $data['model'] ?? 'gemini-pro';
$actualModel = $modelMap[$requestedModel] ?? $requestedModel; // Fallback to raw name if not in map

// Use the V1 endpoint which is generally more stable than V1BETA
$url = "https://generativelanguage.googleapis.com/v1/models/$actualModel:generateContent?key=" . trim($GEMINI_API_KEY);

// Format Payload for Gemini Multimodal
$geminiContents = [];
$lastPromptSnippet = "";

foreach ($data['messages'] as $m) {
    $role = ($m['role'] === 'assistant') ? 'model' : 'user';
    $parts = [];

    if (is_string($m['content'])) {
        $parts[] = ["text" => $m['content']];
        if ($role === 'user') $lastPromptSnippet = $m['content'];
    } else {
        foreach ($m['content'] as $p) {
            if (isset($p['text'])) {
                $parts[] = ["text" => $p['text']];
                if ($role === 'user') $lastPromptSnippet = $p['text'];
            }
            if (isset($p['source'])) {
                $parts[] = [
                    "inlineData" => [
                        "mimeType" => $p['source']['media_type'],
                        "data" => $p['source']['data']
                    ]
                ];
                if ($role === 'user') $lastPromptSnippet .= " [IMAGE] ";
            }
        }
    }
    $geminiContents[] = ["role" => $role, "parts" => $parts];
}

$payload = [
    "contents" => $geminiContents,
    "systemInstruction" => [
        "parts" => [["text" => $data['system'] ?? "You are a civil engineering mentor."]]
    ]
];

// Execute cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 4. LOGGING FOR TEACHER DASHBOARD ---
$responseData = json_decode($response, true);
$usage = $responseData['usageMetadata'] ?? ['promptTokenCount' => 0, 'candidatesTokenCount' => 0];
$studentName = $data['student_name'] ?? 'Unknown';

$cleanPrompt = str_replace(["\r", "\n", "|"], " ", substr($lastPromptSnippet, 0, 100));
$logLine = date('Y-m-d H:i:s') . " | $studentName | $model | In:{$usage['promptTokenCount']} | Out:{$usage['candidatesTokenCount']} | PROMPT: $cleanPrompt\n";

file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

// Send response back to index.html
http_response_code($httpCode);
echo $response;
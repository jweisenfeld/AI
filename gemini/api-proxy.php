<?php
/**
 * Gemini API Proxy with Student Authentication
 * Pasco School District - Orion High School
 */

set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

// --- 1. SETUP PATHS ---
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']); 
$secretsFile = $accountRoot . '/.secrets/geminikey.php';
$studentFile = $accountRoot . '/.secrets/25-26-S1-Passwords-Combined.csv'; 

function send_error($message, $code = 500) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// --- 2. AUTHENTICATION LOGIC ---
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (isset($data['action']) && $data['action'] === 'verify_login') {
    $id = $data['student_id'] ?? '';
    $pass = $data['password'] ?? '';
    
    if (($handle = fopen($studentFile, "r")) !== FALSE) {
        fgetcsv($handle); // Skip header row
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Adjust indices based on your CSV: Id is index 2, Password is index 6, Full Name is index 9
            if ($row[2] === $id && $row[6] === $pass) {
                echo json_encode(['success' => true, 'student_name' => $row[9]]);
                exit;
            }
        }
        fclose($handle);
    }
    send_error("Invalid Credentials", 401);
}

// --- 3. PROXY TO GEMINI ---
if (!file_exists($secretsFile)) send_error("API Key Missing");
require_once($secretsFile); // Defines $GEMINI_API_KEY

$model = $data['model'] ?? 'gemini-1.5-pro';
$url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$GEMINI_API_KEY";

// Reformat our custom "system" field into the Gemini "systemInstruction" format
$geminiPayload = [
    "systemInstruction" => ["parts" => [["text" => $data['system']]]],
    "contents" => array_map(function($m) {
        return ["role" => ($m['role'] === 'assistant' ? 'model' : 'user'), "parts" => [["text" => $m['content']]]];
    }, $data['messages'])
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($geminiPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$usage = json_decode($response, true)['usageMetadata'] ?? ['promptTokenCount' => 0, 'candidatesTokenCount' => 0];

// --- 4. LOGGING ---
$team = $data['student_name'] ?? ($data['team_id'] ?? 'unknown');
$logLine = date('Y-m-d H:i:s') . " | $team | In:{$usage['promptTokenCount']} | Out:{$usage['candidatesTokenCount']}\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

echo $response;
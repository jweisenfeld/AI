<?php
/**
 * OHS Gemini Proxy - Updated for Student Logging, Stable Models, and Context Caching
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$studentFile   = $accountRoot . '/.secrets/student_roster.csv';
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';

function send_error($msg, $details = null) {
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// --- 1. LOGIN ROUTE ---
if (isset($data['action']) && $data['action'] === 'verify_login') {
    if (!file_exists($studentFile)) send_error("Roster file missing.");
    $handle = fopen($studentFile, "r");
    fgetcsv($handle); 
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (trim($row[2]) == trim($data['student_id'] ?? '') && trim($row[6]) == trim($data['password'] ?? '')) {
            echo json_encode(['success' => true, 'student_name' => $row[9]]);
            fclose($handle); exit;
        }
    }
    fclose($handle);
    send_error("Invalid credentials.");
}

// --- 2. STUDENT INTERACTION LOGGING (New Action) ---
if (isset($data['action']) && $data['action'] === 'log_interaction') {
    $studentId = $data['student_id'] ?? 'unknown_student';
    $logContent = $data['log'] ?? '';
    
    // Create a per-student log file in a 'logs' directory
    if (!is_dir('student_logs')) { mkdir('student_logs', 0777, true); }
    $logFilename = "student_logs/" . preg_replace('/[^a-z0-9]/i', '_', $studentId) . ".txt";
    
    $timestamp = date('Y-m-d H:i:s');
    $formattedLog = "--- Entry: $timestamp ---\n" . $logContent . "\n";
    
    file_put_contents($logFilename, $formattedLog, FILE_APPEND);
    echo json_encode(['success' => true]);
    exit;
}

// --- 3. CHAT ROUTE ---
if (!file_exists($secretsFile)) send_error("API Key file missing.");
require_once($secretsFile);

$modelMap = [
    "gemini-3-pro-preview"  => "gemini-3-pro-preview",
    "gemini-2.5-flash"      => "gemini-2.5-flash",
    "gemini-2.5-flash-lite" => "gemini-2.5-flash-lite"
];

$requested   = $data['model'] ?? 'gemini-2.5-flash';
$actualModel = $modelMap[$requested] ?? "gemini-2.5-flash";

// ── Context Cache lookup ─────────────────────────────────────────────────────
// If a cache exists for the Pasco Municipal Code, attach it so we don't have
// to resend the 9 MB document with every request.
$cachedContentName = null;
if (file_exists($cacheNameFile)) {
    $saved = trim(file_get_contents($cacheNameFile));
    if (!empty($saved)) {
        $cachedContentName = $saved;
    }
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/$actualModel:generateContent?key=" . trim($GEMINI_API_KEY);

$contents = [];
if (isset($data['messages'])) {
    foreach ($data['messages'] as $m) {
        // Handle both 'model' and 'assistant' keys for flexibility
        $role = ($m['role'] === 'assistant' || $m['role'] === 'model') ? 'model' : 'user';
        $parts = [];
        
        // Handle array-based parts from the new index.html
        if (is_array($m['parts'])) {
            foreach ($m['parts'] as $p) {
                if (isset($p['text'])) {
                    $parts[] = ["text" => $p['text']];
                }
                if (isset($p['inline_data'])) {
                    $parts[] = ["inlineData" => $p['inline_data']];
                }
            }
        } 
        // Backward compatibility for string-based content
        elseif (isset($m['content']) && is_string($m['content'])) {
            $parts[] = ["text" => $m['content']];
        }
        
        if (!empty($parts)) {
            $contents[] = ["role" => $role, "parts" => $parts];
        }
    }
}

$payload = [
    "contents"         => $contents,
    "systemInstruction" => ["parts" => [["text" => $data['system'] ?? "You are a civil engineer."]]]
];

// Attach the cached Pasco Municipal Code if available.
// When present, Gemini reads tokens from the cache at a much lower cost
// rather than re-processing the full document each call.
if ($cachedContentName !== null) {
    $payload['cachedContent'] = $cachedContentName;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 4. USAGE LOGGING (Teacher Dashboard Data) ---
$responseData = json_decode($response, true);
$usage = $responseData['usageMetadata'] ?? ['promptTokenCount' => 0, 'candidatesTokenCount' => 0];
$studentName = $data['student_name'] ?? 'Unknown';

$studentID     = $data['student_id'] ?? 'unknown';
$cachedTokens  = $usage['cachedContentTokenCount'] ?? 0;
$cacheFlag     = $cachedContentName ? "CACHED($cachedTokens)" : "NO_CACHE";
$logLine = date('Y-m-d H:i:s') . " | $studentName | $actualModel | In:{$usage['promptTokenCount']} | Out:{$usage['candidatesTokenCount']} | $cacheFlag | ID:$studentID\n";
file_put_contents('gemini_usage.log', $logLine, FILE_APPEND);

http_response_code($httpCode);
echo $response;
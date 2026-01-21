<?php
require_once __DIR__ . '/../.secrets/geminikey2.php'; // Adjust path as needed

// Get the JSON data from the frontend request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No message provided']);
    exit;
}

// Prepare the Gemini API endpoint (using 2.5 Flash)
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $GEMINI_API_KEY;

// Format the request for Gemini
$requestData = [
    "contents" => [[
        "parts" => [["text" => $data['message']]]
    ]]
];

// Execute the request via cURL
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

// Send the AI's response back to your JavaScript
header('Content-Type: application/json');
echo $response;
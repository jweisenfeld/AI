<?php
/**
 * Student Authentication System
 * Pasco School District - Community Engineering Project
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Get account root (one level above public_html)
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$csvPath = $accountRoot . '/AI/Misc/25-26-S2-Passwords-Combined.csv';

// Login endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'login') {
        $studentId = $input['studentId'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($studentId) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Student ID and password are required']);
            exit;
        }

        // Check CSV file
        if (!file_exists($csvPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Student database not found']);
            exit;
        }

        $file = fopen($csvPath, 'r');
        $headers = fgetcsv($file); // Skip header row
        $authenticated = false;

        while (($row = fgetcsv($file)) !== false) {
            if ($row[0] === $studentId && $row[1] === $password) {
                $authenticated = true;
                break;
            }
        }
        fclose($file);

        if ($authenticated) {
            $_SESSION['student_id'] = $studentId;
            $_SESSION['login_time'] = time();
            echo json_encode([
                'success' => true,
                'studentId' => $studentId,
                'sessionId' => session_id()
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid student ID or password']);
        }

    } elseif ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check session status
    if (isset($_SESSION['student_id'])) {
        echo json_encode([
            'authenticated' => true,
            'studentId' => $_SESSION['student_id'],
            'sessionId' => session_id()
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
}

<?php
/**
 * API Endpoint to Record Sent Emails
 *
 * Called by Python script when an email is sent to record it in the database
 * before the tracking pixel is triggered.
 *
 * POST /email-tracking/api/record_sent.php
 * Body: JSON with email_id, student_id, recipient_email, recipient_name, subject, sent_at
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration (same as track.php)
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';  // Update this
$DB_USER = 'fikrttmy_tracker';         // Update this
$DB_PASS = 'm}^KBykDn5r]';    // Update this

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$required = ['email_id', 'recipient_email', 'subject', 'sent_at'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Insert sent email record
    $stmt = $pdo->prepare("
        INSERT INTO email_sent
        (email_id, student_id, recipient_email, recipient_name, subject, sent_at)
        VALUES
        (:email_id, :student_id, :recipient_email, :recipient_name, :subject, :sent_at)
        ON DUPLICATE KEY UPDATE
        student_id = :student_id,
        recipient_name = :recipient_name,
        subject = :subject
    ");

    $stmt->execute([
        ':email_id' => $data['email_id'],
        ':student_id' => $data['student_id'] ?? null,
        ':recipient_email' => $data['recipient_email'],
        ':recipient_name' => $data['recipient_name'] ?? null,
        ':subject' => $data['subject'],
        ':sent_at' => $data['sent_at']
    ]);

    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'email_id' => $data['email_id'],
        'message' => 'Email recorded successfully'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
?>

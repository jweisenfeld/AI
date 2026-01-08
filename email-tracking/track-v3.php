<?php
/**
 * Email Tracking Pixel Endpoint (Version 3 - Smart data extraction)
 *
 * Records when an email is opened by serving a 1x1 transparent GIF
 * and logging the open event to MySQL database.
 *
 * Now supports passing student data via URL parameters for intelligent tracking!
 *
 * Usage:
 * <img src="https://psd1.net/email-tracking/track.php?id=UUID&sid=STUDENT_ID&name=NAME&email=EMAIL&subj=SUBJECT" />
 */

date_default_timezone_set('America/Los_Angeles');  // Pacific Time

// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';

// Get email ID and optional student data from query parameters
$email_id = $_GET['id'] ?? null;
$student_id = $_GET['sid'] ?? 'UNKNOWN';
$recipient_name = $_GET['name'] ?? 'Unknown';
$recipient_email = $_GET['email'] ?? 'unknown@example.com';
$subject = $_GET['subj'] ?? 'Tracked Email';

// URL decode the parameters (in case they were encoded)
$student_id = urldecode($student_id);
$recipient_name = urldecode($recipient_name);
$recipient_email = urldecode($recipient_email);
$subject = urldecode($subject);

if ($email_id) {
    try {
        // Connect to database
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Collect tracking data
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $opened_at = date('Y-m-d H:i:s');

        // Auto-create email_sent record if it doesn't exist
        // Now uses the smart data passed via URL parameters!
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO email_sent
            (email_id, student_id, recipient_email, recipient_name, subject, sent_at)
            VALUES
            (:email_id, :student_id, :recipient_email, :recipient_name, :subject, :sent_at)
        ");
        $stmt->execute([
            ':email_id' => $email_id,
            ':student_id' => $student_id,
            ':recipient_email' => $recipient_email,
            ':recipient_name' => $recipient_name,
            ':subject' => $subject,
            ':sent_at' => $opened_at
        ]);

        // Insert open event
        $stmt = $pdo->prepare("
            INSERT INTO email_opens
            (email_id, opened_at, ip_address, user_agent, referer)
            VALUES
            (:email_id, :opened_at, :ip_address, :user_agent, :referer)
        ");

        $stmt->execute([
            ':email_id' => $email_id,
            ':opened_at' => $opened_at,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent,
            ':referer' => $referer
        ]);

    } catch (PDOException $e) {
        // Silently fail - don't break email display
        error_log("Tracking error: " . $e->getMessage());
    }
}

// Serve 1x1 transparent GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 1x1 transparent GIF (43 bytes)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
?>

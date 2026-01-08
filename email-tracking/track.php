<?php
/**
 * Email Tracking Pixel Endpoint
 *
 * Records when an email is opened by serving a 1x1 transparent GIF
 * and logging the open event to MySQL database.
 *
 * Usage: <img src="https://psd1.net/email-tracking/track.php?id=EMAIL_ID" width="1" height="1" />
 */

// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';  // Update this with your actual database name
$DB_USER = 'fikrttmy_tracker';         // Update this with your database user
$DB_PASS = 'm}^KBykDn5r]';    // Update this with your database password

// Get email ID from query parameter
$email_id = isset($_GET['id']) ? $_GET['id'] : null;

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

        // Optional: Log to file for debugging
        // error_log("Email opened: $email_id by $ip_address\n", 3, __DIR__ . '/tracking.log');

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

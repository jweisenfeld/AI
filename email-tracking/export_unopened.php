<?php
/**
 * Export Unopened Emails to CSV
 *
 * Downloads a CSV file containing all emails that have not been opened.
 * Useful for follow-up campaigns.
 */

// Database configuration (same as dashboard.php)
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';

// Password protection
$DASHBOARD_PASSWORD = 'physics2026';
session_start();

if (!isset($_SESSION['authenticated'])) {
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get all unopened emails
    $stmt = $pdo->query("
        SELECT
            student_id,
            recipient_name,
            recipient_email,
            subject,
            sent_at
        FROM email_stats
        WHERE status = 'Not Opened'
        ORDER BY sent_at DESC
    ");
    $unopened = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Generate CSV
$filename = 'unopened_emails_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// CSV header row
fputcsv($output, ['Student ID', 'Name', 'Email', 'Subject', 'Sent At']);

// Data rows
foreach ($unopened as $row) {
    fputcsv($output, [
        $row['student_id'],
        $row['recipient_name'],
        $row['recipient_email'],
        $row['subject'],
        $row['sent_at']
    ]);
}

fclose($output);
exit;
?>

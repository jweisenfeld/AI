<?php
/**
 * Campaign Status Endpoint
 *
 * Returns open status for every recipient in a campaign.
 * Used by track_unopened.py.
 *
 * GET /email-tracking/campaign_status.php?cid=<uuid>
 *
 * Response (JSON):
 * {
 *   "campaign_id": "...",
 *   "label": "pd5: ...",
 *   "recipients": {
 *     "ajarvix@psd1.org": "Opened",
 *     "mkelso@psd1.org":  "Not Opened",
 *     ...
 *   }
 * }
 */

header('Content-Type: application/json');

$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';

$cid = trim($_GET['cid'] ?? '');

if ($cid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: cid']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT label FROM campaigns WHERE campaign_id = :cid");
    $stmt->execute([':cid' => $cid]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['error' => 'Campaign not found']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT LOWER(recipient_email) AS email, status
        FROM email_stats
        WHERE campaign_id = :cid
    ");
    $stmt->execute([':cid' => $cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipients = [];
    foreach ($rows as $row) {
        $recipients[$row['email']] = $row['status'];
    }

    echo json_encode([
        'campaign_id' => $cid,
        'label'       => $campaign['label'],
        'recipients'  => $recipients,
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>

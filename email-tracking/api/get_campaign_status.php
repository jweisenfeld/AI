<?php
/**
 * API Endpoint: Get Campaign Open Status
 *
 * Returns open status for every recipient in a campaign.
 * Used by track_unopened.py — no HTML scraping needed.
 *
 * GET /email-tracking/api/get_campaign_status.php?campaign_id=<uuid>
 *
 * Response (JSON):
 * {
 *   "campaign_id": "...",
 *   "label": "PD Week-Out Reminder (3/25/2026)",
 *   "recipients": {
 *     "ajarvix@psd1.org": "Opened",
 *     "mkelso@psd1.org":  "Not Opened",
 *     ...
 *   }
 * }
 *
 * Returns {} with a 404 if campaign_id is not found in the campaigns table.
 * Returns {} with a 400 if campaign_id param is missing.
 */

header('Content-Type: application/json');

$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';

$campaign_id = trim($_GET['campaign_id'] ?? '');

if ($campaign_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: campaign_id']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Verify the campaign exists
    $stmt = $pdo->prepare("SELECT label FROM campaigns WHERE campaign_id = :cid");
    $stmt->execute([':cid' => $campaign_id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['error' => 'Campaign not found', 'campaign_id' => $campaign_id]);
        exit;
    }

    // Get open status for every recipient in this campaign
    $stmt = $pdo->prepare("
        SELECT
            LOWER(recipient_email) AS email,
            status
        FROM email_stats
        WHERE campaign_id = :cid
    ");
    $stmt->execute([':cid' => $campaign_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recipients = [];
    foreach ($rows as $row) {
        $recipients[$row['email']] = $row['status'];
    }

    echo json_encode([
        'campaign_id' => $campaign_id,
        'label'       => $campaign['label'],
        'recipients'  => $recipients,
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>

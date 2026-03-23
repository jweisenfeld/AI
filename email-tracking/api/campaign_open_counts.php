<?php
/**
 * API Endpoint: Campaign Open Counts
 *
 * Returns the raw open count for every recipient in a campaign.
 * Used by track_unopened.py to bucket recipients into 0 / 1 / 2+ opens.
 *
 * GET /email-tracking/api/campaign_open_counts.php?campaign_id=<uuid>
 *
 * Response (JSON):
 * {
 *   "campaign_id": "...",
 *   "label": "pd6: ...",
 *   "recipients": {
 *     "ajarvix@psd1.org": 3,
 *     "mkelso@psd1.org":  0,
 *     ...
 *   }
 * }
 *
 * Notes:
 *   - "recipients" is always a JSON object (never an array), even when empty.
 *   - open_count comes from the email_stats VIEW (COUNT of email_opens rows).
 *   - A count of 1 is ambiguous — likely the Outlook display-mode preview.
 *     Treat count >= 2 as "genuinely opened by the recipient".
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

// Validate UUID format to keep ModSecurity happy and avoid SQL injection
if (!preg_match('/^[0-9a-f-]{36}$/i', $campaign_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid campaign_id format']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Verify campaign exists and get its label
    $stmt = $pdo->prepare("SELECT label FROM campaigns WHERE campaign_id = :cid");
    $stmt->execute([':cid' => $campaign_id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['error' => 'Campaign not found', 'campaign_id' => $campaign_id]);
        exit;
    }

    // Fetch open_count per recipient from the email_stats view
    $stmt = $pdo->prepare("
        SELECT LOWER(recipient_email) AS email, open_count
        FROM email_stats
        WHERE campaign_id = :cid
    ");
    $stmt->execute([':cid' => $campaign_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build associative array — keyed by email so it always encodes as a JSON object
    $recipients = new stdClass();
    foreach ($rows as $row) {
        $recipients->{$row['email']} = (int) $row['open_count'];
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

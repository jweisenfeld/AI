<?php
/**
 * Campaign Status Endpoint
 *
 * Returns open status for every recipient in a campaign.
 * Used by track_unopened.py.
 *
 * GET /email-tracking/campaign_status.php?folder=pd5
 *
 * Pass the folder name (e.g. pd5, board7) — not the UUID.
 * PHP resolves the campaign_id internally, keeping UUIDs out of the URL
 * and away from ModSecurity UUID/SQL-injection pattern rules.
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

// Accept folder name — simple alphanumeric, safe from WAF rules
$folder = trim($_GET['folder'] ?? '');

if ($folder === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: folder']);
    exit;
}

// Whitelist: only allow alphanumeric + hyphen/underscore folder names
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folder)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid folder name']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Resolve folder name → campaign (most recently created if multiple)
    $stmt = $pdo->prepare("
        SELECT campaign_id, label
        FROM campaigns
        WHERE folder_name = :folder
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':folder' => $folder]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['error' => 'No campaign found for folder', 'folder' => $folder]);
        exit;
    }

    $cid = $campaign['campaign_id'];

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

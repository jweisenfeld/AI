<?php
/**
 * API Endpoint: Register a Campaign + Backfill email_sent records
 *
 * Called once per campaign by backfill_campaign.py.
 * Inserts a row into campaigns and stamps matching email_sent rows
 * with the campaign_id using a subject LIKE pattern.
 *
 * POST /email-tracking/api/register_campaign.php
 * Body: JSON {
 *   "api_key":          "physics2026",
 *   "campaign_id":      "<uuid>",
 *   "folder_name":      "pd5",
 *   "label":            "PD Week-Out Reminder (3/25/2026)",
 *   "subject_template": "{{LastName}}, we are one week out from PD on 3/25 🛫",
 *   "sent_date":        "2026-03-19",
 *   "like_pattern":     "%, we are one week out from PD on 3/25 🛫"
 * }
 *
 * Response: {
 *   "success": true,
 *   "campaign_inserted": true/false (false = already existed),
 *   "records_updated": 56
 * }
 */

header('Content-Type: application/json');

$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';
$API_KEY = 'physics2026';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Auth
if (($data['api_key'] ?? '') !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Required fields
foreach (['campaign_id', 'folder_name', 'label', 'like_pattern'] as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Insert campaign (skip if already registered)
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO campaigns (campaign_id, folder_name, label, subject_template, sent_date)
        VALUES (:cid, :folder, :label, :template, :sent_date)
    ");
    $stmt->execute([
        ':cid'      => $data['campaign_id'],
        ':folder'   => $data['folder_name'],
        ':label'    => $data['label'],
        ':template' => $data['subject_template'] ?? null,
        ':sent_date'=> $data['sent_date'] ?? null,
    ]);
    $campaign_inserted = $stmt->rowCount() > 0;

    // Backfill: stamp matching email_sent rows that aren't tagged yet
    $stmt = $pdo->prepare("
        UPDATE email_sent
        SET campaign_id = :cid
        WHERE subject LIKE :pattern
          AND campaign_id IS NULL
    ");
    $stmt->execute([
        ':cid'     => $data['campaign_id'],
        ':pattern' => $data['like_pattern'],
    ]);
    $records_updated = $stmt->rowCount();

    echo json_encode([
        'success'            => true,
        'campaign_id'        => $data['campaign_id'],
        'campaign_inserted'  => $campaign_inserted,
        'records_updated'    => $records_updated,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
?>

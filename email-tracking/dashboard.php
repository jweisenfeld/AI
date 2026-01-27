<?php
/**
 * Email Tracking Dashboard
 *
 * Displays email open statistics in a simple table
 *
 * URL: https://psd1.net/email-tracking/dashboard.php
 */

// Database configuration (same as track.php)
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';  // Update this
$DB_USER = 'fikrttmy_tracker';         // Update this
$DB_PASS = 'm}^KBykDn5r]';    // Update this

// Optional: Add simple password protection
$DASHBOARD_PASSWORD = 'physics2026';  // Change this!
session_start();

if (!isset($_SESSION['authenticated'])) {
    if (isset($_POST['password']) && $_POST['password'] === $DASHBOARD_PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        // Show login form
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Email Tracking Dashboard - Login</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f0f0f0; padding: 50px; }
                .login-box { background: white; max-width: 400px; margin: 0 auto; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
                button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
                button:hover { background: #0056b3; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>Email Tracking Dashboard</h2>
                <form method="post">
                    <input type="password" name="password" placeholder="Enter password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle flush database request
$flush_message = '';
if (isset($_GET['flush']) && $_GET['flush'] === 'confirm') {
    try {
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Delete in correct order due to foreign key constraints
        $pdo->exec("DELETE FROM email_opens");
        $pdo->exec("DELETE FROM email_sent");
        $flush_message = 'success';
    } catch (PDOException $e) {
        $flush_message = 'error: ' . $e->getMessage();
    }
}

// Handle sorting
$sort_column = $_GET['sort'] ?? 'sent_at';
$sort_dir = $_GET['dir'] ?? 'DESC';

// Whitelist valid sort columns to prevent SQL injection
$valid_columns = ['student_id', 'recipient_name', 'recipient_email', 'subject', 'sent_at', 'open_count', 'status'];
if (!in_array($sort_column, $valid_columns)) {
    $sort_column = 'sent_at';
}
$sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';

// Fetch data from database
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get overall statistics
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_sent,
            SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) as total_opened,
            ROUND(SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as open_rate
        FROM email_stats
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get individual email records with sorting
    $stmt = $pdo->query("
        SELECT
            student_id,
            recipient_name,
            recipient_email,
            subject,
            sent_at,
            open_count,
            first_opened_at,
            status
        FROM email_stats
        ORDER BY $sort_column $sort_dir
        LIMIT 100
    ");
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Helper function to generate sort link
function sortLink($column, $label, $current_sort, $current_dir) {
    $new_dir = ($current_sort === $column && $current_dir === 'ASC') ? 'DESC' : 'ASC';
    $arrow = '';
    if ($current_sort === $column) {
        $arrow = $current_dir === 'ASC' ? ' ▲' : ' ▼';
    }
    return "<a href=\"?sort=$column&dir=$new_dir\" style=\"color: white; text-decoration: none;\">$label$arrow</a>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Tracking Dashboard</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 28px;
        }
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-title {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }
        .card-value {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-collapse: collapse;
        }
        thead {
            background: #007bff;
            color: white;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .status-opened {
            display: inline-block;
            padding: 4px 12px;
            background: #28a745;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-not-opened {
            display: inline-block;
            padding: 4px 12px;
            background: #dc3545;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .open-count {
            background: #e7f3ff;
            color: #007bff;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        .logout {
            float: right;
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .logout:hover {
            background: #c82333;
        }
        .refresh {
            float: right;
            margin-right: 10px;
            background: #28a745;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .refresh:hover {
            background: #218838;
        }
        .export {
            float: right;
            margin-right: 10px;
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .export:hover {
            background: #5a6268;
        }
        th a {
            color: white;
            text-decoration: none;
        }
        th a:hover {
            text-decoration: underline;
        }
        .flush {
            float: right;
            margin-right: 10px;
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
        .flush:hover {
            background: #c82333;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal {
            background: white;
            padding: 30px;
            border-radius: 8px;
            max-width: 450px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .modal p {
            margin-bottom: 20px;
            color: #666;
        }
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .modal-buttons a, .modal-buttons button {
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            border: none;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        .btn-cancel:hover {
            background: #5a6268;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            Email Tracking Dashboard
            <a href="?logout" class="logout">Logout</a>
            <a href="dashboard.php" class="refresh">Refresh</a>
            <a href="export_unopened.php" class="export">Export Unopened</a>
            <button class="flush" onclick="document.getElementById('flushModal').style.display='flex'">Flush Database</button>
        </h1>

        <?php if ($flush_message === 'success'): ?>
            <div class="alert alert-success">
                Database flushed successfully. All tracking data has been deleted.
            </div>
        <?php elseif ($flush_message): ?>
            <div class="alert alert-error">
                Error flushing database: <?= htmlspecialchars(str_replace('error: ', '', $flush_message)) ?>
            </div>
        <?php endif; ?>

        <div class="stats-cards">
            <div class="card">
                <div class="card-title">Total Sent</div>
                <div class="card-value"><?= $stats['total_sent'] ?? 0 ?></div>
            </div>
            <div class="card">
                <div class="card-title">Total Opened</div>
                <div class="card-value"><?= $stats['total_opened'] ?? 0 ?></div>
            </div>
            <div class="card">
                <div class="card-title">Open Rate</div>
                <div class="card-value"><?= $stats['open_rate'] ?? 0 ?>%</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th><?= sortLink('student_id', 'Student ID', $sort_column, $sort_dir) ?></th>
                    <th><?= sortLink('recipient_name', 'Name', $sort_column, $sort_dir) ?></th>
                    <th><?= sortLink('recipient_email', 'Email', $sort_column, $sort_dir) ?></th>
                    <th><?= sortLink('subject', 'Subject', $sort_column, $sort_dir) ?></th>
                    <th><?= sortLink('sent_at', 'Sent', $sort_column, $sort_dir) ?></th>
                    <th><?= sortLink('open_count', 'Opens', $sort_column, $sort_dir) ?></th>
                    <th>First Opened</th>
                    <th><?= sortLink('status', 'Status', $sort_column, $sort_dir) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($emails)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                            No emails tracked yet. Send your first tracked email!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($emails as $email): ?>
                        <tr>
                            <td><?= htmlspecialchars($email['student_id'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($email['recipient_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($email['recipient_email']) ?></td>
                            <td><?= htmlspecialchars(substr($email['subject'], 0, 50)) ?><?= strlen($email['subject']) > 50 ? '...' : '' ?></td>
                            <td><?= date('m/d/Y g:i A', strtotime($email['sent_at'])) ?></td>
                            <td><span class="open-count"><?= $email['open_count'] ?></span></td>
                            <td><?= $email['first_opened_at'] ? date('m/d/Y g:i A', strtotime($email['first_opened_at'])) : '-' ?></td>
                            <td>
                                <span class="status-<?= strtolower(str_replace(' ', '-', $email['status'])) ?>">
                                    <?= $email['status'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Flush Confirmation Modal -->
    <div class="modal-overlay" id="flushModal">
        <div class="modal">
            <h3>Flush Database?</h3>
            <p>This will permanently delete <strong>ALL</strong> email tracking data including sent records and open events. This action cannot be undone!</p>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="document.getElementById('flushModal').style.display='none'">Cancel</button>
                <a href="?flush=confirm" class="btn-danger">Yes, Delete Everything</a>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}
?>

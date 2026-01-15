<?php
/**
 * Admin Dashboard - View Student Usage and Costs
 * Pasco School District - Community Engineering Project
 */

// Simple password protection (change this password!)
$ADMIN_PASSWORD = 'admin123';  // CHANGE THIS!

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_authenticated'] = true;
    } else {
        $error = 'Invalid password';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin-dashboard.php');
    exit;
}

if (!isset($_SESSION['admin_authenticated'])) {
    // Show login form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Admin Login</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                background: linear-gradient(135deg, #1a5276, #2874a6);
                margin: 0;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            }
            input {
                padding: 10px;
                font-size: 16px;
                border: 2px solid #ddd;
                border-radius: 6px;
                width: 250px;
            }
            button {
                padding: 10px 20px;
                background: #1a5276;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
            }
            .error { color: red; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>Admin Dashboard Login</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="Admin Password" required autofocus>
                <button type="submit">Login</button>
                <?php if (isset($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Read log file
$logFile = __DIR__ . '/logs/student_requests.jsonl';
$logs = [];
$totalCost = 0;
$studentStats = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if ($entry) {
            $logs[] = $entry;
            $totalCost += $entry['total_cost_usd'] ?? 0;

            $studentId = $entry['student_id'] ?? 'unknown';
            if (!isset($studentStats[$studentId])) {
                $studentStats[$studentId] = [
                    'requests' => 0,
                    'total_cost' => 0,
                    'total_tokens' => 0
                ];
            }
            $studentStats[$studentId]['requests']++;
            $studentStats[$studentId]['total_cost'] += $entry['total_cost_usd'] ?? 0;
            $studentStats[$studentId]['total_tokens'] += $entry['total_tokens'] ?? 0;
        }
    }
}

// Sort logs by timestamp (most recent first)
usort($logs, function($a, $b) {
    return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
});

// Get filter parameters
$filterStudent = $_GET['student'] ?? '';
$filterDays = $_GET['days'] ?? 7;

// Apply filters
if ($filterStudent) {
    $logs = array_filter($logs, function($log) use ($filterStudent) {
        return ($log['student_id'] ?? '') === $filterStudent;
    });
}

if ($filterDays > 0) {
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-$filterDays days"));
    $logs = array_filter($logs, function($log) use ($cutoffDate) {
        return ($log['timestamp'] ?? '') >= $cutoffDate;
    });
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Claude Usage Monitor</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #1a5276, #2874a6);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.8rem; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: #1a5276;
        }
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .filters form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters select, .filters button {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .filters button {
            background: #1a5276;
            color: white;
            border: none;
            cursor: pointer;
        }
        .section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section h2 {
            margin-bottom: 20px;
            color: #1a5276;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .cost {
            color: #28a745;
            font-weight: 600;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            border-radius: 6px;
        }
        .preview {
            max-width: 400px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.85rem;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>📊 Admin Dashboard</h1>
            <p>Claude AI Usage Monitor - Community Engineering Project</p>
        </div>
        <a href="?logout=1"><button class="logout-btn">Logout</button></a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Requests</h3>
            <div class="value"><?= number_format(count($logs)) ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Cost</h3>
            <div class="value cost">$<?= number_format($totalCost, 4) ?></div>
        </div>
        <div class="stat-card">
            <h3>Active Students</h3>
            <div class="value"><?= count($studentStats) ?></div>
        </div>
        <div class="stat-card">
            <h3>Avg Cost/Request</h3>
            <div class="value cost">$<?= count($logs) > 0 ? number_format($totalCost / count($logs), 6) : '0.000000' ?></div>
        </div>
    </div>

    <div class="filters">
        <form method="GET">
            <label>Filter by Student:
                <select name="student">
                    <option value="">All Students</option>
                    <?php foreach (array_keys($studentStats) as $sid): ?>
                        <option value="<?= htmlspecialchars($sid) ?>" <?= $sid === $filterStudent ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sid) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Time Period:
                <select name="days">
                    <option value="1" <?= $filterDays == 1 ? 'selected' : '' ?>>Last 24 hours</option>
                    <option value="7" <?= $filterDays == 7 ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30" <?= $filterDays == 30 ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="0">All time</option>
                </select>
            </label>
            <button type="submit">Apply Filters</button>
            <a href="admin-dashboard.php"><button type="button">Reset</button></a>
        </form>
    </div>

    <div class="section">
        <h2>Student Usage Summary</h2>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Requests</th>
                    <th>Total Tokens</th>
                    <th>Total Cost</th>
                    <th>Avg Cost/Request</th>
                </tr>
            </thead>
            <tbody>
                <?php
                uasort($studentStats, function($a, $b) {
                    return $b['total_cost'] <=> $a['total_cost'];
                });
                foreach ($studentStats as $sid => $stats):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($sid) ?></strong></td>
                    <td><?= $stats['requests'] ?></td>
                    <td><?= number_format($stats['total_tokens']) ?></td>
                    <td class="cost">$<?= number_format($stats['total_cost'], 4) ?></td>
                    <td class="cost">$<?= number_format($stats['total_cost'] / $stats['requests'], 6) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Recent Requests (<?= count($logs) ?> total)</h2>
        <?php if (count($logs) > 100): ?>
            <div class="warning">⚠️ Showing last 100 requests. Use filters to narrow results.</div>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Student</th>
                    <th>Model</th>
                    <th>Tokens (In/Out)</th>
                    <th>Cost</th>
                    <th>Preview</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($logs, 0, 100) as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['timestamp'] ?? '') ?></td>
                    <td><strong><?= htmlspecialchars($log['student_id'] ?? 'unknown') ?></strong></td>
                    <td><?= htmlspecialchars(str_replace('claude-', '', $log['model'] ?? '')) ?></td>
                    <td>
                        <?= number_format($log['input_tokens'] ?? 0) ?> /
                        <?= number_format($log['output_tokens'] ?? 0) ?>
                    </td>
                    <td class="cost">$<?= number_format($log['total_cost_usd'] ?? 0, 6) ?></td>
                    <td class="preview"><?= htmlspecialchars($log['message_preview'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Export Data</h2>
        <p>Download the raw log file for further analysis:</p>
        <a href="logs/student_requests.jsonl" download>
            <button style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; margin-top: 10px;">
                📥 Download JSONL Log File
            </button>
        </a>
    </div>

</body>
</html>

<?php
/**
 * OHS Engineering Grant - Comprehensive Reporting Dashboard
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$logFile = __DIR__ . '/gemini_usage.log';
$logDir = __DIR__ . '/student_logs/';
$grantAmount = 1000.00; // Your Amentum Grant Total

// Read log file
$lines = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

// Log format (9 pipe-separated fields):
// timestamp | studentName | model | In:XXXX | Out:XXXX | Cached:XXXX | FILE_FLAG | CACHE_FLAG | ID:studentID
//    [0]          [1]        [2]      [3]        [4]         [5]           [6]          [7]           [8]

// Compute stats from log data (skip TTFB lines which have < 5 parts)
$stats = ['total_in' => 0, 'total_out' => 0, 'students' => [], 'chat_lines' => 0];
foreach ($lines as $line) {
    $parts = explode('|', $line);
    if (count($parts) < 5) continue;

    $stats['chat_lines']++;
    $studentName = trim($parts[1]);
    $stats['students'][$studentName] = true;

    preg_match('/In:(\d+)/', trim($parts[3]), $inMatch);
    preg_match('/Out:(\d+)/', trim($parts[4]), $outMatch);
    $stats['total_in'] += intval($inMatch[1] ?? 0);
    $stats['total_out'] += intval($outMatch[1] ?? 0);
}

// Build per-student summary
$studentSummary = [];
foreach ($lines as $line) {
    $parts = explode('|', $line);
    if (count($parts) < 9) continue;

    $studentName = trim($parts[1]);
    $studentID   = trim(substr(trim($parts[8]), 3)); // strip "ID:" prefix

    if (!isset($studentSummary[$studentName])) {
        $studentSummary[$studentName] = ['id' => $studentID, 'count' => 0, 'last' => ''];
    }
    $studentSummary[$studentName]['count']++;
    $studentSummary[$studentName]['last'] = trim($parts[0]);
}
uasort($studentSummary, fn($a, $b) => $b['count'] <=> $a['count']);

// Build recent activity list
$recentActivity = [];
foreach ($lines as $line) {
    $parts = explode('|', $line);
    if (count($parts) < 9) continue;

    // ID is at position 8: "ID:studentID"
    $studentID = trim(substr(trim($parts[8]), 3));

    $recentActivity[] = [
        'time'   => trim($parts[0]),
        'name'   => trim($parts[1]),
        'id'     => $studentID,
        'model'  => trim($parts[2]),
        'tokens' => trim($parts[3]) . " / " . trim($parts[4]),
        'cache'  => trim($parts[7]),
    ];
}
// Show most recent 50 (reverse so newest is first)
$recentActivity = array_reverse($recentActivity);

// 2. Financials (Gemini 2.5/3 Estimated Pricing)
$estCost = ($stats['total_in'] / 1000000 * 0.15) + ($stats['total_out'] / 1000000 * 0.60);
$remaining = $grantAmount - $estCost;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grant Reporting Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 30px; background: #f4f7f9; color: #333; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .big-num { font-size: 2.2em; font-weight: bold; color: #1a73e8; }
        .label { font-size: 0.85em; color: #666; text-transform: uppercase; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #1a73e8; color: white; }
        .prompt-text { font-family: monospace; font-size: 0.85em; color: #d63384; }
        .student-link { color: #1a73e8; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

    <header style="display:flex; justify-content: space-between; align-items: center;">
        <h1>📊 OHS Engineering Project Grant Status</h1>
        <button onclick="window.location.reload()" class="card" style="cursor:pointer">🔄 Refresh Data</button>
    </header>

    <div class="grid">
        <div class="card">
            <div class="label">Grant Spent</div>
            <div class="big-num">$<?php echo number_format($estCost, 2); ?></div>
        </div>
        <div class="card">
            <div class="label">Grant Remaining</div>
            <div class="big-num">$Weisenfeld</div>
        </div>
        <div class="card">
            <div class="label">Total Interactions</div>
            <div class="big-num"><?php echo $stats['chat_lines']; ?></div>
        </div>
        <div class="card">
            <div class="label">Active Students</div>
            <div class="big-num"><?php echo count($stats['students']); ?></div>
        </div>
    </div>

    <div class="card">
        <h2>Recent Activity & Logs</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Student</th>
                    <th>Model</th>
                    <th>Tokens (In / Out)</th>
                    <th>Cache</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($recentActivity, 0, 50) as $entry): ?>
                <tr>
                    <td><?php echo htmlspecialchars($entry['time']); ?></td>
                    <td>
                        <a href="student_logs/<?php echo urlencode($entry['id']); ?>.txt"
                        class="student-link" target="_blank">
                            <?php echo htmlspecialchars($entry['name']); ?>
                        </a>
                    </td>
                    <td><small><?php echo htmlspecialchars($entry['model']); ?></small></td>
                    <td><?php echo htmlspecialchars($entry['tokens']); ?></td>
                    <td class="prompt-text"><?php echo htmlspecialchars($entry['cache']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="margin-top:30px">
        <h2>All Students (<?php echo count($studentSummary); ?> unique)</h2>
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Interactions</th>
                    <th>Last Active</th>
                    <th>Log</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($studentSummary as $name => $info): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td><?php echo htmlspecialchars($info['id']); ?></td>
                    <td><?php echo $info['count']; ?></td>
                    <td><?php echo htmlspecialchars($info['last']); ?></td>
                    <td>
                        <a href="student_logs/<?php echo urlencode($info['id']); ?>.txt"
                        class="student-link" target="_blank">view</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
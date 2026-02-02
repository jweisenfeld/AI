<?php
/**
 * OHS Engineering Grant - Comprehensive Reporting Dashboard
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$logFile = __DIR__ . '/gemini_usage.log';
$logDir = __DIR__ . '/student_logs/';
$grantAmount = 1000.00; // Your Amentum Grant Total

// 1. Data Processing
$lines = file_exists($logFile) ? file($logFile) : [];
$lines = array_reverse($lines);

$stats = [
    'total_in' => 0,
    'total_out' => 0,
    'students' => [],
    'models' => []
];

foreach ($lines as $line) {
    $parts = explode('|', $line);
    if (count($parts) < 5) continue;

    $student = trim($parts[1]);
    $model = trim($parts[2]);
    
    preg_match('/In:(\d+)/', $parts[3], $inM);
    preg_match('/Out:(\d+)/', $parts[4], $outM);
    
    $in = (int)($inM[1] ?? 0);
    $out = (int)($outM[1] ?? 0);

    $stats['total_in'] += $in;
    $stats['total_out'] += $out;
    $stats['students'][$student] = ($stats['students'][$student] ?? 0) + ($in + $out);
    $stats['models'][$model] = ($stats['models'][$model] ?? 0) + 1;
}

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
        <h1>📊 OHS Engineering Grant Status</h1>
        <button onclick="window.location.reload()" class="card" style="cursor:pointer">🔄 Refresh Data</button>
    </header>

    <div class="grid">
        <div class="card">
            <div class="label">Grant Spent</div>
            <div class="big-num">$<?php echo number_format($estCost, 2); ?></div>
        </div>
        <div class="card">
            <div class="label">Grant Remaining</div>
            <div class="big-num">$<?php echo number_format($remaining, 2); ?></div>
        </div>
        <div class="card">
            <div class="label">Total Interactions</div>
            <div class="big-num"><?php echo count($lines); ?></div>
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
                    <th>Tokens</th>
                    <th>Last Intent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($lines, 0, 50) as $line): 
                    $parts = explode('|', $line);
                    if (count($parts) < 6) continue;
                ?>
                <tr>
                    <td><?php echo trim($parts[0]); ?></td>
                    <td>
                        <a href="student_logs/<?php echo urlencode(trim($parts[1])); ?>.txt" class="student-link" target="_blank">
                            <?php echo trim($parts[1]); ?>
                        </a>
                    </td>
                    <td><small><?php echo trim($parts[2]); ?></small></td>
                    <td><?php echo trim($parts[3]) . " / " . trim($parts[4]); ?></td>
                    <td class="prompt-text"><?php echo htmlspecialchars(substr($parts[5], 8)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
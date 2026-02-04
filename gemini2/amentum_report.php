<?php
/**
 * OHS Engineering Grant - Comprehensive Reporting Dashboard
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$logFile = __DIR__ . '/gemini_usage.log';
$logDir = __DIR__ . '/student_logs/';
$grantAmount = 1000.00; // Your Amentum Grant Total

// 1. UPDATED DATA PROCESSING
$recentActivity = [];
foreach ($lines as $line) {
    $parts = explode('|', $line);
    // Ensure we have all necessary parts including Student ID if added to proxy
    if (count($parts) < 5) continue;

    $timestamp = trim($parts[0]);
    $studentName = trim($parts[1]);
    $modelUsed = trim($parts[2]);
    $tokens = trim($parts[3]) . " / " . trim($parts[4]);
    $intent = isset($parts[5]) ? htmlspecialchars(substr($parts[5], 8)) : '-';

    // RECOMMENDATION: Update proxy to log StudentID. 
    // If ID is not in usage log, we'll use a placeholder logic below.
    $studentID = "STU_" . preg_replace('/[^a-z0-9]/i', '', $studentName); 

    $recentActivity[] = [
        'time' => $timestamp,
        'name' => $studentName,
        'id'   => $studentID, 
        'model'=> $modelUsed,
        'tokens'=> $tokens,
        'intent'=> $intent
    ];
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
                <?php 
                // Display all interactions to correctly show different students
                foreach (array_slice($recentActivity, 0, 50) as $entry): 
                ?>
                <tr>
                    <td><?php echo $entry['time']; ?></td>
                    <td>
                        <a href="student_logs/<?php echo urlencode($entry['id']); ?>.txt" 
                        class="student-link" target="_blank">
                            <?php echo htmlspecialchars($entry['name']); ?>
                        </a>
                    </td>
                    <td><small><?php echo htmlspecialchars($entry['model']); ?></small></td>
                    <td><?php echo htmlspecialchars($entry['tokens']); ?></td>
                    <td class="prompt-text"><?php echo $entry['intent']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
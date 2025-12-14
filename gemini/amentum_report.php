<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Dashboard - Gemini Usage</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h1 { margin-top: 0; color: #1a73e8; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .stat-box { background: #e8f0fe; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-num { font-size: 2em; font-weight: bold; color: #1967d2; }
        .stat-label { color: #5f6368; font-size: 0.9em; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; color: #5f6368; }
        tr:hover { background-color: #f1f1f1; }
        .code-snippet { font-family: monospace; color: #d63384; font-size: 0.85em; }
        .refresh-btn { float: right; padding: 8px 16px; background: #1a73e8; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>

<div class="card">
    <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="refresh-btn">🔄 Refresh</a>
    <h1>📊 Engineering Grant Dashboard</h1>
    
    <?php
    $logFile = __DIR__ . '/gemini_usage.log';
    
    if (!file_exists($logFile)) {
        echo "<p>No logs found yet. Get students to start chatting!</p>";
        exit;
    }

    $lines = file($logFile);
    $lines = array_reverse($lines); // Show newest first

    $totalInput = 0;
    $totalOutput = 0;
    $teamStats = [];

    // Parse Logs
    foreach ($lines as $line) {
        // Expected format: Date | TeamID | In:100 | Out:200 ...
        $parts = explode('|', $line);
        if (count($parts) < 3) continue;

        $team = trim($parts[1]);
        
        // Extract Numbers using Regex
        preg_match('/In:(\d+)/', $line, $inMatch);
        preg_match('/Out:(\d+)/', $line, $outMatch);
        
        $in = isset($inMatch[1]) ? (int)$inMatch[1] : 0;
        $out = isset($outMatch[1]) ? (int)$outMatch[1] : 0;

        $totalInput += $in;
        $totalOutput += $out;

        // Per Team Stats
        if (!isset($teamStats[$team])) $teamStats[$team] = 0;
        $teamStats[$team] += ($in + $out);
    }

    // Cost Calculation (Est. Gemini 1.5 Pro Pricing)
    // Input: $1.25 / million
    // Output: $5.00 / million
    $cost = ($totalInput / 1000000 * 1.25) + ($totalOutput / 1000000 * 5.00);
    $formattedCost = number_format($cost, 4);
    $percentUsed = ($cost / 1000) * 100; // Based on $1,000 grant
    ?>

    <div class="stat-grid">
        <div class="stat-box">
            <div class="stat-num">$<?php echo $formattedCost; ?></div>
            <div class="stat-label">Total Spent</div>
        </div>
        <div class="stat-box">
            <div class="stat-num"><?php echo number_format($percentUsed, 2); ?>%</div>
            <div class="stat-label">Grant Used</div>
        </div>
        <div class="stat-box">
            <div class="stat-num"><?php echo number_format($totalInput + $totalOutput); ?></div>
            <div class="stat-label">Total Tokens</div>
        </div>
        <div class="stat-box">
            <div class="stat-num"><?php echo count($teamStats); ?></div>
            <div class="stat-label">Active Teams</div>
        </div>
    </div>
</div>

<div class="card">
    <h3>Recent Activity</h3>
    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Team</th>
                <th>Usage</th>
                <th>Prompt / Details</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Display last 50 entries
            $count = 0;
            foreach ($lines as $line) {
                if ($count >= 50) break;
                
                $parts = explode('|', $line);
                if (count($parts) < 3) continue;
                
                $time = $parts[0];
                $team = $parts[1];
                // The rest is stats/prompts
                $details = implode(' | ', array_slice($parts, 2));

                echo "<tr>";
                echo "<td>" . htmlspecialchars($time) . "</td>";
                echo "<td><strong>" . htmlspecialchars($team) . "</strong></td>";
                
                // Highlight high usage
                if (strpos($details, 'Out:2000') !== false || strpos($details, 'Out:3') !== false) { 
                     // Simple heuristic for "big answer"
                     echo "<td style='color:orange'>" . htmlspecialchars(substr($details, 0, 30)) . "...</td>";
                } else {
                     echo "<td>" . htmlspecialchars(substr($details, 0, 30)) . "...</td>";
                }
                
                // Show Prompt if available (from Option 2)
                if (strpos($line, 'PROMPT:') !== false) {
                    $pParts = explode('PROMPT:', $line);
                    echo "<td class='code-snippet'>" . htmlspecialchars(substr($pParts[1], 0, 80)) . "</td>";
                } else {
                    echo "<td style='color:#999'>-</td>";
                }
                echo "</tr>";
                $count++;
            }
            ?>
        </tbody>
    </table>
</div>

</body>
</html>
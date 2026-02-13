<?php
/**
 * Claude Usage Dashboard (server-side)
 * Reads claude_usage.log directly — no file upload needed.
 *
 * Access control: form-based login with session cookie.
 * Credentials stored in ~/.secrets/claudekey.php as
 * 'DASHBOARD_USER' and 'DASHBOARD_PASS'.
 */

session_start();

// --- Load credentials ---
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/claudekey.php';
$secrets = is_readable($secretsFile) ? require $secretsFile : [];
$validUser = $secrets['DASHBOARD_USER'] ?? 'Admin';
$validPass = $secrets['DASHBOARD_PASS'] ?? 'Pas99301!';

// --- Handle logout ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

// --- Handle login form submission ---
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user'], $_POST['pass'])) {
    if (hash_equals($validUser, $_POST['user']) && hash_equals($validPass, $_POST['pass'])) {
        $_SESSION['dashboard_auth'] = true;
    } else {
        $loginError = 'Invalid username or password.';
    }
}

// --- Show login form if not authenticated ---
if (empty($_SESSION['dashboard_auth'])) {
    ?><!DOCTYPE html>
    <html lang="en"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Login</title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; background: #0f172a; color: #e2e8f0;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 40px;
                     width: 360px; max-width: 90vw; }
        .login-box h2 { margin-bottom: 24px; font-size: 1.3rem; }
        .login-box label { display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 4px; }
        .login-box input { width: 100%; padding: 10px 14px; border: 1px solid #334155; border-radius: 8px;
                           background: #0f172a; color: #e2e8f0; font-size: 1rem; margin-bottom: 16px; }
        .login-box input:focus { outline: none; border-color: #6366f1; }
        .login-box button { width: 100%; padding: 12px; background: #6366f1; color: white; border: none;
                            border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .login-box button:hover { background: #4f46e5; }
        .error { color: #ef4444; font-size: 0.9rem; margin-bottom: 12px; }
    </style>
    </head><body>
    <form class="login-box" method="POST">
        <h2>Claude Dashboard Login</h2>
        <?php if ($loginError): ?><div class="error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
        <label for="user">Username</label>
        <input type="text" id="user" name="user" autocomplete="username" required autofocus>
        <label for="pass">Password</label>
        <input type="password" id="pass" name="pass" autocomplete="current-password" required>
        <button type="submit">Sign In</button>
    </form>
    </body></html><?php
    exit;
}

// --- Read log file ---
$logFile = __DIR__ . '/claude_usage.log';
$entries = [];
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry)) {
            $entries[] = $entry;
        }
    }
}
$entriesJson = json_encode($entries, JSON_UNESCAPED_SLASHES);
$entryCount = count($entries);
$lastUpdated = $entryCount > 0 ? ($entries[$entryCount - 1]['timestamp'] ?? 'unknown') : 'no data';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude Usage Dashboard</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --card-border: #334155;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #f59e0b;
            --accent2: #6366f1;
            --danger: #ef4444;
            --success: #10b981;
            --blue: #3b82f6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            padding: 24px;
        }
        h1 { font-size: 1.8rem; margin-bottom: 4px; }
        .subtitle { color: var(--text-muted); margin-bottom: 24px; font-size: 0.9rem; }
        .subtitle code { background: var(--card); padding: 2px 6px; border-radius: 4px; }
        .refresh-btn {
            background: var(--accent2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .refresh-btn:hover { opacity: 0.9; }

        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
        }
        .summary-card .label { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 4px; }
        .summary-card .value { font-size: 1.8rem; font-weight: 700; }
        .summary-card .detail { color: var(--text-muted); font-size: 0.8rem; margin-top: 4px; }
        .summary-card.danger .value { color: var(--danger); }
        .summary-card.success .value { color: var(--success); }
        .summary-card.accent .value { color: var(--accent); }
        .summary-card.blue .value { color: var(--blue); }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 0;
            flex-wrap: wrap;
        }
        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.95rem;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .tab:hover { color: var(--text); }
        .tab.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card);
            border-radius: 12px;
            overflow: hidden;
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--card-border);
        }
        th {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent2);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            user-select: none;
        }
        th:hover { background: rgba(99, 102, 241, 0.2); }
        th .sort-arrow { margin-left: 4px; font-size: 0.7rem; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.03); }
        td.number { text-align: right; font-variant-numeric: tabular-nums; }
        td.danger { color: var(--danger); font-weight: 600; }
        td.muted { color: var(--text-muted); }

        /* Bar chart */
        .bar-chart { margin: 16px 0; }
        .bar-row {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            gap: 12px;
        }
        .bar-label {
            min-width: 100px;
            font-size: 0.85rem;
            text-align: right;
            color: var(--text-muted);
        }
        .bar-track {
            flex: 1;
            height: 24px;
            background: rgba(255,255,255,0.05);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            padding: 0 8px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #000;
            min-width: fit-content;
        }
        .bar-fill.haiku { background: var(--success); }
        .bar-fill.sonnet { background: var(--accent); }
        .bar-fill.opus { background: var(--danger); }
        .bar-fill.default { background: var(--blue); }

        /* Cost estimate */
        .cost-note {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            font-size: 0.9rem;
        }
        .cost-note strong { color: var(--danger); }

        /* Filters */
        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters label { color: var(--text-muted); font-size: 0.85rem; }
        .filters select, .filters input {
            background: var(--card);
            border: 1px solid var(--card-border);
            color: var(--text);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        /* Scrollable table wrapper */
        .table-wrap {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--card-border);
        }

        @media (max-width: 768px) {
            body { padding: 12px; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <h1>Claude Usage Dashboard</h1>
    <p class="subtitle">
        <code><?php echo $entryCount; ?></code> log entries loaded &middot;
        Last entry: <code><?php echo htmlspecialchars($lastUpdated); ?></code> &middot;
        Generated: <code><?php echo date('Y-m-d H:i:s T'); ?></code>
    </p>
    <a class="refresh-btn" href="javascript:location.reload()">Refresh Data</a>
    <a class="refresh-btn" href="dashboard.php?logout" style="background: #475569; margin-left: 8px;">Logout</a>

    <!-- Summary Cards -->
    <div class="summary-grid" id="summary-cards"></div>

    <!-- Cost Warning -->
    <div class="cost-note" id="cost-note"></div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="showTab('students', this)">Students</button>
        <button class="tab" onclick="showTab('daily', this)">Daily</button>
        <button class="tab" onclick="showTab('hourly', this)">Hourly</button>
        <button class="tab" onclick="showTab('models', this)">Models</button>
        <button class="tab" onclick="showTab('prompts', this)">Recent Prompts</button>
        <button class="tab" onclick="showTab('alerts', this)">Alerts</button>
    </div>

    <!-- Student Breakdown -->
    <div class="tab-content active" id="tab-students">
        <div class="table-wrap">
            <table id="student-table">
                <thead><tr>
                    <th onclick="sortTable('student-table', 0)">Student ID <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 1)">Requests <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 2)">Input Tokens <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 3)">Output Tokens <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 4)">Total Tokens <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 5)">Est. Cost <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 6)">Max Msg Count <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 7)">Models Used <span class="sort-arrow"></span></th>
                    <th onclick="sortTable('student-table', 8)">Last Active <span class="sort-arrow"></span></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Daily Breakdown -->
    <div class="tab-content" id="tab-daily">
        <div class="bar-chart" id="daily-chart"></div>
        <div class="table-wrap">
            <table id="daily-table">
                <thead><tr>
                    <th onclick="sortTable('daily-table', 0)">Date</th>
                    <th onclick="sortTable('daily-table', 1)">Requests</th>
                    <th onclick="sortTable('daily-table', 2)">Unique Students</th>
                    <th onclick="sortTable('daily-table', 3)">Total Tokens</th>
                    <th onclick="sortTable('daily-table', 4)">Est. Cost</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Hourly Breakdown -->
    <div class="tab-content" id="tab-hourly">
        <div class="bar-chart" id="hourly-chart"></div>
    </div>

    <!-- Model Breakdown -->
    <div class="tab-content" id="tab-models">
        <div class="bar-chart" id="model-chart"></div>
        <div class="table-wrap">
            <table id="model-table">
                <thead><tr>
                    <th>Model</th>
                    <th>Requests</th>
                    <th>Total Tokens</th>
                    <th>Est. Cost</th>
                    <th>% of Total Cost</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Recent Prompts -->
    <div class="tab-content" id="tab-prompts">
        <div class="filters">
            <label>Student:</label>
            <select id="prompt-student-filter" onchange="renderPrompts()">
                <option value="">All Students</option>
            </select>
        </div>
        <div class="table-wrap">
            <table id="prompt-table">
                <thead><tr>
                    <th>Timestamp</th>
                    <th>Student</th>
                    <th>Model</th>
                    <th>Msg#</th>
                    <th>User Prompt (first 500 chars)</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Alerts -->
    <div class="tab-content" id="tab-alerts">
        <div id="alerts-list"></div>
    </div>

    <script>
        // Log data injected server-side by PHP — no upload needed
        const allEntries = <?php echo $entriesJson; ?>;

        let currentSort = {};

        // Approximate token costs per model ($ per 1M tokens)
        const COSTS = {
            'claude-haiku-4-5':          { input: 0.80,  output: 4.00 },
            'claude-haiku-3-5-20241022': { input: 0.80,  output: 4.00 },
            'claude-sonnet-4-5':         { input: 3.00,  output: 15.00 },
            'claude-sonnet-4-20250514':  { input: 3.00,  output: 15.00 },
            'claude-opus-4-6':           { input: 15.00, output: 75.00 },
            'claude-opus-4-20250514':    { input: 15.00, output: 75.00 },
        };

        function estimateCost(model, inputTokens, outputTokens) {
            const rates = COSTS[model] || { input: 3.00, output: 15.00 };
            return (inputTokens / 1e6) * rates.input + (outputTokens / 1e6) * rates.output;
        }

        // ============ RENDER ALL ============
        if (allEntries.length > 0) {
            renderSummary();
            renderStudents();
            renderDaily();
            renderHourly();
            renderModels();
            renderPrompts();
            renderAlerts();
        } else {
            document.getElementById('summary-cards').innerHTML =
                '<div class="summary-card"><div class="label">No Data</div><div class="value" style="font-size:1.2rem">Log file is empty or not found.</div></div>';
            document.getElementById('cost-note').style.display = 'none';
        }

        // ============ SUMMARY ============
        function renderSummary() {
            const totalRequests = allEntries.length;
            const students = new Set(allEntries.map(e => e.student_id).filter(s => s && s !== 'unknown'));
            let totalInput = 0, totalOutput = 0, totalCost = 0;
            for (const e of allEntries) {
                const it = e.input_tokens || 0;
                const ot = e.output_tokens || 0;
                totalInput += it;
                totalOutput += ot;
                totalCost += estimateCost(e.model, it, ot);
            }
            const days = new Set(allEntries.map(e => e.timestamp?.substring(0, 10)));
            const avgPerDay = (totalRequests / Math.max(days.size, 1)).toFixed(0);

            document.getElementById('summary-cards').innerHTML = `
                <div class="summary-card accent"><div class="label">Total Requests</div><div class="value">${totalRequests.toLocaleString()}</div><div class="detail">${avgPerDay}/day avg over ${days.size} days</div></div>
                <div class="summary-card blue"><div class="label">Unique Students</div><div class="value">${students.size}</div></div>
                <div class="summary-card"><div class="label">Total Tokens</div><div class="value">${((totalInput + totalOutput) / 1e6).toFixed(1)}M</div><div class="detail">${(totalInput/1e6).toFixed(1)}M in / ${(totalOutput/1e6).toFixed(1)}M out</div></div>
                <div class="summary-card danger"><div class="label">Estimated Cost</div><div class="value">$${totalCost.toFixed(2)}</div><div class="detail">Based on published API pricing</div></div>
            `;

            // Top spender warning
            const byStudent = {};
            for (const e of allEntries) {
                const sid = e.student_id || 'unknown';
                if (!byStudent[sid]) byStudent[sid] = { cost: 0, tokens: 0, requests: 0 };
                const it = e.input_tokens || 0, ot = e.output_tokens || 0;
                byStudent[sid].cost += estimateCost(e.model, it, ot);
                byStudent[sid].tokens += it + ot;
                byStudent[sid].requests += 1;
            }
            const sorted = Object.entries(byStudent).sort((a, b) => b[1].cost - a[1].cost);
            const top = sorted[0];
            const topPct = totalCost > 0 ? ((top[1].cost / totalCost) * 100).toFixed(0) : 0;

            document.getElementById('cost-note').innerHTML = `
                <strong>Top spender: ${esc(top[0])}</strong> &mdash; $${top[1].cost.toFixed(2)} (${topPct}% of total cost) with ${top[1].requests} requests and ${(top[1].tokens/1e6).toFixed(1)}M tokens.
                ${top[1].requests > 100 ? '<br>This student has unusually high usage and may need a conversation with their teacher.' : ''}
            `;
        }

        // ============ STUDENTS ============
        function renderStudents() {
            const byStudent = {};
            for (const e of allEntries) {
                const sid = e.student_id || 'unknown';
                if (!byStudent[sid]) byStudent[sid] = { requests: 0, input: 0, output: 0, cost: 0, maxMsg: 0, models: new Set(), lastActive: '' };
                const s = byStudent[sid];
                s.requests++;
                const it = e.input_tokens || 0, ot = e.output_tokens || 0;
                s.input += it;
                s.output += ot;
                s.cost += estimateCost(e.model, it, ot);
                s.maxMsg = Math.max(s.maxMsg, e.message_count || 0);
                s.models.add(e.model_tier || e.model || '?');
                if (e.timestamp > s.lastActive) s.lastActive = e.timestamp;
            }

            const tbody = document.querySelector('#student-table tbody');
            tbody.innerHTML = Object.entries(byStudent)
                .sort((a, b) => b[1].cost - a[1].cost)
                .map(([sid, s]) => `<tr>
                    <td><strong>${esc(sid)}</strong></td>
                    <td class="number">${s.requests}</td>
                    <td class="number">${s.input.toLocaleString()}</td>
                    <td class="number">${s.output.toLocaleString()}</td>
                    <td class="number ${(s.input+s.output) > 1000000 ? 'danger' : ''}">${(s.input+s.output).toLocaleString()}</td>
                    <td class="number ${s.cost > 10 ? 'danger' : ''}">\$${s.cost.toFixed(2)}</td>
                    <td class="number ${s.maxMsg > 50 ? 'danger' : ''}">${s.maxMsg}</td>
                    <td class="muted">${[...s.models].join(', ')}</td>
                    <td class="muted">${s.lastActive}</td>
                </tr>`).join('');

            // Populate prompt filter
            const select = document.getElementById('prompt-student-filter');
            select.innerHTML = '<option value="">All Students</option>' +
                Object.keys(byStudent).sort().map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
        }

        // ============ DAILY ============
        function renderDaily() {
            const byDay = {};
            for (const e of allEntries) {
                const day = e.timestamp?.substring(0, 10) || 'unknown';
                if (!byDay[day]) byDay[day] = { requests: 0, students: new Set(), tokens: 0, cost: 0 };
                byDay[day].requests++;
                if (e.student_id && e.student_id !== 'unknown') byDay[day].students.add(e.student_id);
                const it = e.input_tokens || 0, ot = e.output_tokens || 0;
                byDay[day].tokens += it + ot;
                byDay[day].cost += estimateCost(e.model, it, ot);
            }

            const maxReq = Math.max(...Object.values(byDay).map(d => d.requests));

            document.getElementById('daily-chart').innerHTML = Object.entries(byDay)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .map(([day, d]) => `
                    <div class="bar-row">
                        <div class="bar-label">${day}</div>
                        <div class="bar-track">
                            <div class="bar-fill default" style="width: ${(d.requests/maxReq*100)}%">${d.requests} req &mdash; \$${d.cost.toFixed(2)}</div>
                        </div>
                    </div>
                `).join('');

            const tbody = document.querySelector('#daily-table tbody');
            tbody.innerHTML = Object.entries(byDay)
                .sort((a, b) => a[0].localeCompare(b[0]))
                .map(([day, d]) => `<tr>
                    <td>${day}</td>
                    <td class="number">${d.requests}</td>
                    <td class="number">${d.students.size}</td>
                    <td class="number">${d.tokens.toLocaleString()}</td>
                    <td class="number">\$${d.cost.toFixed(2)}</td>
                </tr>`).join('');
        }

        // ============ HOURLY ============
        function renderHourly() {
            const byHour = {};
            for (let h = 0; h < 24; h++) byHour[h] = 0;
            for (const e of allEntries) {
                const h = parseInt(e.timestamp?.substring(11, 13) || '0');
                byHour[h]++;
            }
            const maxH = Math.max(...Object.values(byHour));

            document.getElementById('hourly-chart').innerHTML = Object.entries(byHour)
                .map(([h, count]) => {
                    const hour = parseInt(h);
                    const isSchool = (hour >= 7 && hour < 17);
                    return `<div class="bar-row">
                        <div class="bar-label">${hour.toString().padStart(2,'0')}:00</div>
                        <div class="bar-track">
                            <div class="bar-fill ${isSchool ? 'default' : 'opus'}" style="width: ${count > 0 ? Math.max((count/maxH*100), 2) : 0}%">${count > 0 ? count : ''}</div>
                        </div>
                    </div>`;
                }).join('') +
                '<p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 12px;">Blue = school hours (7AM-5PM) | Red = after hours</p>';
        }

        // ============ MODELS ============
        function renderModels() {
            const byModel = {};
            let totalCost = 0;
            for (const e of allEntries) {
                const m = e.model || 'unknown';
                if (!byModel[m]) byModel[m] = { requests: 0, tokens: 0, cost: 0 };
                byModel[m].requests++;
                const it = e.input_tokens || 0, ot = e.output_tokens || 0;
                byModel[m].tokens += it + ot;
                const cost = estimateCost(m, it, ot);
                byModel[m].cost += cost;
                totalCost += cost;
            }

            const maxReq = Math.max(...Object.values(byModel).map(d => d.requests));
            document.getElementById('model-chart').innerHTML = Object.entries(byModel)
                .sort((a, b) => b[1].cost - a[1].cost)
                .map(([model, d]) => {
                    const tier = model.includes('haiku') ? 'haiku' : model.includes('opus') ? 'opus' : 'sonnet';
                    return `<div class="bar-row">
                        <div class="bar-label" style="min-width:200px; font-size:0.8rem">${model}</div>
                        <div class="bar-track">
                            <div class="bar-fill ${tier}" style="width: ${(d.requests/maxReq*100)}%">${d.requests} req &mdash; \$${d.cost.toFixed(2)}</div>
                        </div>
                    </div>`;
                }).join('');

            const tbody = document.querySelector('#model-table tbody');
            tbody.innerHTML = Object.entries(byModel)
                .sort((a, b) => b[1].cost - a[1].cost)
                .map(([model, d]) => `<tr>
                    <td>${model}</td>
                    <td class="number">${d.requests}</td>
                    <td class="number">${d.tokens.toLocaleString()}</td>
                    <td class="number">\$${d.cost.toFixed(2)}</td>
                    <td class="number">${totalCost > 0 ? ((d.cost/totalCost)*100).toFixed(1) : 0}%</td>
                </tr>`).join('');
        }

        // ============ PROMPTS ============
        function renderPrompts() {
            const filter = document.getElementById('prompt-student-filter').value;
            const entries = allEntries
                .filter(e => e.user_text)
                .filter(e => !filter || e.student_id === filter)
                .slice(-200)
                .reverse();

            const tbody = document.querySelector('#prompt-table tbody');
            if (entries.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color: var(--text-muted); padding: 40px;">No prompt data available. Verbose logging (user_text field) was recently added &mdash; new log entries will appear here.</td></tr>';
                return;
            }
            tbody.innerHTML = entries.map(e => `<tr>
                <td class="muted" style="white-space:nowrap">${e.timestamp || ''}</td>
                <td><strong>${esc(e.student_id || 'unknown')}</strong></td>
                <td class="muted">${e.model_tier || ''}</td>
                <td class="number">${e.message_count || ''}</td>
                <td style="max-width:500px; word-break:break-word; font-size:0.85rem">${esc(e.user_text || '')}</td>
            </tr>`).join('');
        }

        // ============ ALERTS ============
        function renderAlerts() {
            const alerts = [];

            const byStudent = {};
            for (const e of allEntries) {
                const sid = e.student_id || 'unknown';
                if (!byStudent[sid]) byStudent[sid] = { requests: 0, tokens: 0, maxMsg: 0, cost: 0, opusCount: 0 };
                byStudent[sid].requests++;
                const it = e.input_tokens || 0, ot = e.output_tokens || 0;
                byStudent[sid].tokens += it + ot;
                byStudent[sid].maxMsg = Math.max(byStudent[sid].maxMsg, e.message_count || 0);
                byStudent[sid].cost += estimateCost(e.model, it, ot);
                if (e.model_tier === 'opus' || (e.model || '').includes('opus')) byStudent[sid].opusCount++;
            }

            for (const [sid, s] of Object.entries(byStudent)) {
                if (s.maxMsg > 100) {
                    alerts.push({ level: 'danger', msg: `<strong>${esc(sid)}</strong> had a conversation with ${s.maxMsg} messages. This causes exponential token growth &mdash; each message re-sends the entire history. The new 50-message cap will prevent this.` });
                }
                if (s.cost > 20) {
                    alerts.push({ level: 'danger', msg: `<strong>${esc(sid)}</strong> has cost an estimated $${s.cost.toFixed(2)}. Consider reviewing their usage.` });
                }
                if (s.opusCount > 10) {
                    alerts.push({ level: 'warning', msg: `<strong>${esc(sid)}</strong> used Opus ${s.opusCount} times. Opus costs 5x more than Sonnet and 19x more than Haiku.` });
                }
                if (s.requests > 200) {
                    alerts.push({ level: 'warning', msg: `<strong>${esc(sid)}</strong> has ${s.requests} total requests &mdash; well above average.` });
                }
            }

            let afterHoursCount = 0;
            for (const e of allEntries) {
                const h = parseInt(e.timestamp?.substring(11, 13) || '0');
                if (h < 7 || h >= 17) afterHoursCount++;
            }
            if (afterHoursCount > 0) {
                alerts.push({ level: 'info', msg: `${afterHoursCount} requests (${((afterHoursCount/allEntries.length)*100).toFixed(0)}%) occurred outside school hours (before 7AM or after 5PM). The throttling limits these to Haiku-only with 10 req/hour.` });
            }

            const unknownCount = allEntries.filter(e => !e.student_id || e.student_id === 'unknown').length;
            if (unknownCount > 0) {
                alerts.push({ level: 'info', msg: `${unknownCount} requests have no student ID (logged before the student_id field was added).` });
            }

            document.getElementById('alerts-list').innerHTML = alerts.length === 0
                ? '<p style="color: var(--text-muted); padding: 20px;">No alerts.</p>'
                : alerts.map(a => `<div style="padding: 12px 16px; margin-bottom: 8px; border-radius: 8px; border-left: 4px solid ${a.level === 'danger' ? 'var(--danger)' : a.level === 'warning' ? 'var(--accent)' : 'var(--blue)'}; background: ${a.level === 'danger' ? 'rgba(239,68,68,0.1)' : a.level === 'warning' ? 'rgba(245,158,11,0.1)' : 'rgba(59,130,246,0.1)'}; font-size: 0.9rem;">${a.msg}</div>`).join('');
        }

        // ============ UTILITIES ============
        function esc(s) {
            const div = document.createElement('div');
            div.textContent = s || '';
            return div.innerHTML;
        }

        function showTab(name, btn) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById('tab-' + name).classList.add('active');
            btn.classList.add('active');
        }

        function sortTable(tableId, colIndex) {
            const table = document.getElementById(tableId);
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            const key = `${tableId}-${colIndex}`;
            const asc = currentSort[key] === 'asc' ? 'desc' : 'asc';
            currentSort[key] = asc;

            rows.sort((a, b) => {
                let va = a.cells[colIndex]?.textContent.trim().replace(/[$,%]/g, '') || '';
                let vb = b.cells[colIndex]?.textContent.trim().replace(/[$,%]/g, '') || '';
                const na = parseFloat(va.replace(/,/g, ''));
                const nb = parseFloat(vb.replace(/,/g, ''));
                if (!isNaN(na) && !isNaN(nb)) {
                    return asc === 'asc' ? na - nb : nb - na;
                }
                return asc === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            });

            tbody.innerHTML = '';
            rows.forEach(r => tbody.appendChild(r));
        }
    </script>
</body>
</html>

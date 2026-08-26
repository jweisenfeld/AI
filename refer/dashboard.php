<?php
// Refer V1 — read-only viewer for referral-log.csv. No editing, no deleting.
session_start();

$ACCESS_CODE = '1901';

if (isset($_GET['logout'])) {
    unset($_SESSION['refer_dash_ok']);
}

if (isset($_POST['code'])) {
    if (trim($_POST['code']) === $ACCESS_CODE) {
        $_SESSION['refer_dash_ok'] = true;
    } else {
        $loginError = 'Wrong code — try again.';
    }
}

if (empty($_SESSION['refer_dash_ok'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Refer — Dashboard</title>
    <style>
      body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f4f6f8; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
      .box { background:#fff; border-radius:16px; padding:32px; max-width:320px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,.12); text-align:center; }
      .box h1 { font-size:19px; margin:0 0 6px; }
      .box p { color:#667085; font-size:14px; margin:0 0 18px; }
      .box input { width:100%; padding:12px; font-size:22px; letter-spacing:8px; text-align:center; border:1px solid #d7dce1; border-radius:10px; margin-bottom:14px; }
      .box button { width:100%; padding:12px; font-size:15px; font-weight:700; border:none; border-radius:10px; background:#2f7dd1; color:#fff; cursor:pointer; }
      .err { color:#a5271f; font-size:13px; margin:-8px 0 14px; }
    </style>
    </head>
    <body>
      <div class="box">
        <h1>Refer Dashboard</h1>
        <p>Enter the access code to view referrals.</p>
        <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <form method="post">
          <input type="password" name="code" inputmode="numeric" maxlength="4" autofocus autocomplete="off">
          <button type="submit">Enter</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$logFile = __DIR__ . '/referral-log.csv';

// referral-log.csv is blocked from direct web access (.htaccess), so the
// only way to download it is through this same password-gated request.
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    if (file_exists($logFile)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="referral-log.csv"');
        readfile($logFile);
    }
    exit;
}

$rows = [];
if (file_exists($logFile)) {
    $fh = fopen($logFile, 'r');
    $header = fgetcsv($fh, 0, ',', '"', '\\');
    $headerCount = count($header);
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        // Older rows may have fewer/more columns than the current header
        // if the CSV schema grew after the file was first created. Pad or
        // trim rather than let array_combine() throw on a count mismatch.
        if (count($r) < $headerCount) {
            $r = array_pad($r, $headerCount, '');
        } elseif (count($r) > $headerCount) {
            $r = array_slice($r, 0, $headerCount);
        }
        $rows[] = array_combine($header, $r);
    }
    fclose($fh);
}

// Newest first.
$rows = array_reverse($rows);

$typeFilter    = isset($_GET['type']) ? trim($_GET['type']) : '';
$teacherFilter = isset($_GET['teacher']) ? trim($_GET['teacher']) : '';
$search        = isset($_GET['q']) ? trim($_GET['q']) : '';

$teachers = [];
foreach ($rows as $r) {
    if (!empty($r['teacher']) && !in_array($r['teacher'], $teachers, true)) {
        $teachers[] = $r['teacher'];
    }
}
sort($teachers);

$filtered = array_filter($rows, function ($r) use ($typeFilter, $teacherFilter, $search) {
    if ($typeFilter !== '' && $r['type'] !== $typeFilter) return false;
    if ($teacherFilter !== '' && $r['teacher'] !== $teacherFilter) return false;
    if ($search !== '') {
        $hay = strtolower(($r['category'] ?? '') . ' ' . ($r['note'] ?? '') . ' ' . ($r['gaps'] ?? '') . ' ' . ($r['student'] ?? ''));
        if (strpos($hay, strtolower($search)) === false) return false;
    }
    return true;
});

$totalCount = count($rows);
$minorCount = count(array_filter($rows, function ($r) { return $r['type'] === 'Minor'; }));
$majorCount = count(array_filter($rows, function ($r) { return $r['type'] === 'Major'; }));
$today = date('Y-m-d');
$todayCount = count(array_filter($rows, function ($r) use ($today) {
    return strpos($r['timestamp'] ?? '', $today) === 0;
}));

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Refer — Dashboard</title>
<style>
  :root{
    --minor: #2f7dd1;
    --major: #e2620f;
    --bg: #f4f6f8;
    --ink: #1c2530;
    --muted: #667085;
    --card: #ffffff;
  }
  * { box-sizing: border-box; }
  body { margin:0; background: var(--bg); color: var(--ink); font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 60px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .sub { color: var(--muted); font-size: 14px; margin: 0 0 20px; }
  .sub a { color: var(--minor); }

  .stats { display:flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
  .stat { background: var(--card); border-radius: 12px; padding: 14px 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); min-width: 110px; }
  .stat .num { font-size: 24px; font-weight: 800; }
  .stat .lbl { font-size: 12.5px; color: var(--muted); }
  .stat.minor .num { color: var(--minor); }
  .stat.major .num { color: var(--major); }

  form.filters { display:flex; gap: 10px; flex-wrap: wrap; align-items:end; margin-bottom: 18px; background: var(--card); padding: 14px 16px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
  form.filters .field { display:flex; flex-direction: column; gap: 4px; }
  form.filters label { font-size: 12px; color: var(--muted); }
  form.filters select, form.filters input[type="text"] {
    padding: 8px 10px; border: 1px solid #d7dce1; border-radius: 8px; font-size: 14px; font-family: inherit;
  }
  form.filters button { padding: 9px 16px; border: none; border-radius: 8px; background: var(--ink); color:#fff; font-weight:600; cursor:pointer; font-size:14px; }
  form.filters .clear { color: var(--muted); font-size: 13px; align-self:center; text-decoration:none; }

  table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
  th, td { text-align: left; padding: 10px 12px; font-size: 13.5px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
  th { background: #f8f9fb; color: var(--muted); font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; }
  tr:last-child td { border-bottom: none; }
  .pill { display:inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; color:#fff; }
  .pill.minor { background: var(--minor); }
  .pill.major { background: var(--major); }
  .empty { padding: 40px; text-align:center; color: var(--muted); }
  .tablewrap { overflow-x: auto; }
  .note { max-width: 260px; white-space: pre-wrap; }

  #reportLink {
    position: fixed; right: 16px; bottom: 16px; z-index: 40;
    display:flex; align-items:center; gap: 6px;
    background: rgba(28,37,48,.92); color: #fff; text-decoration:none;
    font-size: 13.5px; font-weight: 600; padding: 10px 16px; border-radius: 999px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.2);
    transition: transform .08s ease, box-shadow .08s ease;
  }
  #reportLink:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.28); }
</style>
</head>
<body>
<div class="wrap">
  <h1>Refer — Dashboard</h1>
  <p class="sub"><?= $totalCount ?> total referrals logged. <a href="?download=csv">Download CSV</a> &middot; <a href="?logout=1">Sign out</a></p>

  <div class="stats">
    <div class="stat"><div class="num"><?= $totalCount ?></div><div class="lbl">Total</div></div>
    <div class="stat minor"><div class="num"><?= $minorCount ?></div><div class="lbl">Minor</div></div>
    <div class="stat major"><div class="num"><?= $majorCount ?></div><div class="lbl">Major</div></div>
    <div class="stat"><div class="num"><?= $todayCount ?></div><div class="lbl">Today</div></div>
  </div>

  <form class="filters" method="get">
    <div class="field">
      <label for="type">Type</label>
      <select name="type" id="type">
        <option value="" <?= $typeFilter === '' ? 'selected' : '' ?>>All</option>
        <option value="Minor" <?= $typeFilter === 'Minor' ? 'selected' : '' ?>>Minor</option>
        <option value="Major" <?= $typeFilter === 'Major' ? 'selected' : '' ?>>Major</option>
      </select>
    </div>
    <div class="field">
      <label for="teacher">Teacher</label>
      <select name="teacher" id="teacher">
        <option value="">All</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= h($t) ?>" <?= $teacherFilter === $t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="q">Search</label>
      <input type="text" name="q" id="q" value="<?= h($search) ?>" placeholder="category or note text">
    </div>
    <button type="submit">Filter</button>
    <a class="clear" href="dashboard.php">Clear</a>
  </form>

  <div class="tablewrap">
  <?php if (empty($filtered)): ?>
    <div class="empty">No referrals match.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Teacher</th>
          <th>Student</th>
          <th>Type</th>
          <th>Category</th>
          <th>Gaps</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filtered as $r): ?>
        <tr>
          <td><?= h($r['timestamp'] ?? '') ?></td>
          <td><?= h($r['teacher'] ?? '') ?></td>
          <td><?= h($r['student'] ?? '') ?></td>
          <td><span class="pill <?= strtolower($r['type'] ?? '') ?>"><?= h($r['type'] ?? '') ?></span></td>
          <td><?= h($r['category'] ?? '') ?></td>
          <td><?= h($r['gaps'] ?? '') ?></td>
          <td class="note"><?= h($r['note'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>

<a id="reportLink" href="index.html">📝 Report</a>
</body>
</html>

<?php
// Refer V1 — read-only viewer for referral-log.csv. No editing, no deleting.
$logFile = __DIR__ . '/referral-log.csv';

$rows = [];
if (file_exists($logFile)) {
    $fh = fopen($logFile, 'r');
    $header = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($header)) continue;
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
        $hay = strtolower(($r['category'] ?? '') . ' ' . ($r['note'] ?? '') . ' ' . ($r['gaps'] ?? ''));
        if (strpos($hay, strtolower($search)) === false) return false;
    }
    return true;
});

$totalCount = count($rows);
$minorCount = count(array_filter($rows, fn($r) => $r['type'] === 'Minor'));
$majorCount = count(array_filter($rows, fn($r) => $r['type'] === 'Major'));
$today = date('Y-m-d');
$todayCount = count(array_filter($rows, fn($r) => strpos($r['timestamp'] ?? '', $today) === 0));

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
</style>
</head>
<body>
<div class="wrap">
  <h1>Refer — Dashboard</h1>
  <p class="sub"><?= $totalCount ?> total referrals logged. Raw file: <a href="referral-log.csv">referral-log.csv</a></p>

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
</body>
</html>

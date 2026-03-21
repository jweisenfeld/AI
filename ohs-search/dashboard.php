<?php
/**
 * FlightLog — Admin Dashboard
 * Orion High School · psd1.net/ohs-search/dashboard.php
 *
 * Shows corpus stats and low-hit query report.
 * Requires the flightlog_stats() SQL function and query_log table in Supabase.
 */

$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
$secrets     = is_readable($secretsFile) ? require $secretsFile : [];

$supabaseUrl     = rtrim($secrets['SUPABASE_URL']      ?? '', '/');
$supabaseAnonKey = $secrets['SUPABASE_ANON_KEY'] ?? '';

// ── Fetch all stats in one RPC call ───────────────────────────────────────────

$stats = [];
$error = null;

if ($supabaseUrl && $supabaseAnonKey) {
    $ch = curl_init($supabaseUrl . '/rest/v1/rpc/flightlog_stats');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $supabaseAnonKey,
            'Authorization: Bearer ' . $supabaseAnonKey,
        ],
        CURLOPT_POSTFIELDS => '{}',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $stats = json_decode($response, true) ?? [];
    } else {
        $error = "Stats unavailable (HTTP $httpCode). Run the flightlog_stats SQL in Supabase first.";
    }
} else {
    $error = "Secrets not configured.";
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function bar(int $value, int $max, string $color = '#C4622D'): string {
    $pct = $max > 0 ? round(100 * $value / $max) : 0;
    return "<div style='display:flex;align-items:center;gap:8px;margin:3px 0'>
        <div style='flex:1;background:#eee;border-radius:4px;height:14px;overflow:hidden'>
            <div style='width:{$pct}%;background:{$color};height:100%;border-radius:4px'></div>
        </div>
        <span style='font-size:0.8rem;color:#555;min-width:3rem;text-align:right'>" . number_format($value) . "</span>
    </div>";
}

function stat_card(string $label, string $value, string $sub = ''): string {
    return "<div style='background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1.1rem 1.4rem;min-width:140px'>
        <div style='font-size:0.75rem;color:#888;text-transform:uppercase;letter-spacing:.05em'>{$label}</div>
        <div style='font-size:2rem;font-weight:700;color:#1a1a1a;line-height:1.2'>{$value}</div>
        " . ($sub ? "<div style='font-size:0.78rem;color:#6b7280;margin-top:2px'>{$sub}</div>" : '') . "
    </div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FlightLog Dashboard · Orion High School</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #f3f4f6; color: #111827; }

    header {
      background: #1a1a1a; color: white;
      padding: 1rem 2rem; display: flex; align-items: center; gap: 1.25rem;
    }
    .header-logo { height: 52px; width: auto; flex-shrink: 0; }
    .header-text h1 { font-size: 1.3rem; font-weight: 700; letter-spacing: .04em; }
    .header-text h1 span { color: #C4622D; }
    .header-text p { font-size: 0.78rem; opacity: .6; margin-top: 2px; }
    header a { color: rgba(255,255,255,.55); font-size:.8rem; text-decoration:none; }
    header a:hover { color:#fff; }

    .container { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }

    h2 {
      font-size: 1rem; font-weight: 600; text-transform: uppercase;
      letter-spacing: .06em; color: #6b7280; margin: 2rem 0 .75rem;
      padding-bottom: .4rem; border-bottom: 2px solid #C4622D;
    }

    .stat-row { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: .5rem; }

    .panel {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1rem;
    }
    .panel-title {
      font-size: .8rem; font-weight: 600; color: #888;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: .75rem;
    }

    table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    th { text-align:left; padding: .5rem .75rem; background: #f9fafb;
         font-size:.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em;
         border-bottom: 1px solid #e5e7eb; }
    td { padding: .55rem .75rem; border-bottom: 1px solid #f3f4f6; vertical-align:top; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fdf8f5; }

    .badge {
      display:inline-block; padding:2px 8px; border-radius:99px;
      font-size:.72rem; font-weight:600;
    }
    .badge-red   { background:#fee2e2; color:#991b1b; }
    .badge-amber { background:#fef3c7; color:#92400e; }
    .badge-green { background:#d1fae5; color:#065f46; }
    .badge-steel { background:#eaf1f6; color:#3a6780; }

    .error {
      background: #fee2e2; border: 1px solid #fca5a5;
      border-radius: 8px; padding: 1rem 1.25rem; color: #991b1b;
      margin-bottom: 1rem;
    }

    .refreshed { font-size:.75rem; color:#9ca3af; margin-top:1.5rem; text-align:right; }
  </style>
</head>
<body>

<header>
  <img src="orion-logo.png" alt="Orion High School" class="header-logo">
  <div class="header-text">
    <h1>Flight<span>Log</span> <span style="font-weight:400;font-size:.9rem;opacity:.6">Dashboard</span></h1>
    <p>Orion High School · Corpus &amp; Query Stats &nbsp;·&nbsp; <a href="index.html">← Back to FlightLog</a></p>
  </div>
</header>

<div class="container">

<?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
<?php else: ?>

  <?php
    $docsTotal   = (int)($stats['docs_total']    ?? 0);
    $emailTotal  = (int)($stats['email_total']   ?? 0);
    $chunksTotal = (int)($stats['chunks_total']  ?? 0);
    $totalQ      = (int)($stats['total_queries'] ?? 0);
    $zeroHitPct  = (int)($stats['zero_hit_pct']  ?? 0);
    $storage     = $stats['storage']         ?? [];
    $docsByUnit  = $stats['docs_by_unit']    ?? [];
    $docsByWeek  = $stats['docs_by_week']    ?? [];
    $emailsByYr  = $stats['emails_by_year']  ?? [];
    $emailsByWk  = $stats['emails_by_week']  ?? [];
    $lowHit      = $stats['low_hit_queries'] ?? [];

    $dbMb      = (int)($storage['free_tier_mb']  ?? 0);
    $dbPct     = (int)($storage['free_tier_pct'] ?? 0);
    $dbColor   = $dbPct >= 90 ? '#dc2626' : ($dbPct >= 70 ? '#d97706' : '#C4622D');
  ?>

  <!-- ── Top stat cards ───────────────────────────────────────────────────── -->
  <h2>Corpus</h2>
  <div class="stat-row">
    <?= stat_card('Documents', number_format($docsTotal), 'SharePoint files') ?>
    <?= stat_card('Emails', number_format($emailTotal), 'Orion Planning Team') ?>
    <?= stat_card('Vector chunks', number_format($chunksTotal), 'small + large combined') ?>
    <?= stat_card('Queries logged', number_format($totalQ), 'since launch') ?>
    <?= stat_card('Zero-hit rate', $zeroHitPct . '%', 'queries with no results') ?>
  </div>

  <!-- ── Storage ──────────────────────────────────────────────────────────── -->
  <?php if (!empty($storage)): ?>
  <h2>Storage</h2>
  <div class="panel">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;margin-bottom:1.25rem">
      <div>
        <div style="font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.05em">documents table</div>
        <div style="font-size:1.4rem;font-weight:700"><?= htmlspecialchars($storage['documents_table'] ?? '—') ?></div>
        <div style="font-size:.75rem;color:#6b7280">raw text + metadata</div>
      </div>
      <div>
        <div style="font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.05em">chunks table</div>
        <div style="font-size:1.4rem;font-weight:700"><?= htmlspecialchars($storage['chunks_table'] ?? '—') ?></div>
        <div style="font-size:.75rem;color:#6b7280">embeddings + content</div>
      </div>
      <div>
        <div style="font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.05em">total database</div>
        <div style="font-size:1.4rem;font-weight:700"><?= htmlspecialchars($storage['database_total'] ?? '—') ?></div>
        <div style="font-size:.75rem;color:#6b7280">of 500 MB free tier</div>
      </div>
    </div>

    <!-- Free tier progress bar -->
    <div style="font-size:.8rem;color:#374151;margin-bottom:.4rem">
      Supabase free tier usage: <strong><?= $dbMb ?> MB / 500 MB</strong>
      <?php if ($dbPct >= 80): ?>
        &nbsp;<span class="badge badge-red">⚠ Upgrade soon</span>
      <?php elseif ($dbPct >= 60): ?>
        &nbsp;<span class="badge badge-amber">Watch this</span>
      <?php else: ?>
        &nbsp;<span class="badge badge-green">OK</span>
      <?php endif; ?>
    </div>
    <div style="background:#e5e7eb;border-radius:6px;height:18px;overflow:hidden">
      <div style="width:<?= min($dbPct, 100) ?>%;background:<?= $dbColor ?>;height:100%;border-radius:6px;
                  transition:width .3s;display:flex;align-items:center;justify-content:flex-end;padding-right:6px">
        <?php if ($dbPct > 15): ?>
          <span style="font-size:.7rem;color:white;font-weight:600"><?= $dbPct ?>%</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($dbPct >= 80): ?>
    <p style="font-size:.78rem;color:#92400e;margin-top:.6rem">
      Consider upgrading to Supabase Pro ($25/mo) for 8 GB storage and no project pausing.
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── Documents by unit ────────────────────────────────────────────────── -->
  <?php if (!empty($docsByUnit)): ?>
  <h2>Documents by Unit / Folder</h2>
  <div class="panel">
    <div class="panel-title">Top SharePoint folders ingested</div>
    <?php
      $maxN = max(array_column($docsByUnit, 'n'));
      foreach ($docsByUnit as $row):
    ?>
      <div style="margin-bottom:.35rem">
        <div style="font-size:.8rem;color:#374151;margin-bottom:2px"><?= htmlspecialchars($row['unit']) ?></div>
        <?= bar((int)$row['n'], $maxN) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Two-column: doc age + email age ──────────────────────────────────── -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

    <?php if (!empty($docsByWeek)): ?>
    <div class="panel">
      <div class="panel-title">Documents — ingested by week</div>
      <?php
        $maxN = max(array_column($docsByWeek, 'n'));
        foreach ($docsByWeek as $row):
      ?>
        <div style="margin-bottom:.35rem">
          <div style="font-size:.78rem;color:#6b7280"><?= htmlspecialchars($row['week']) ?></div>
          <?= bar((int)$row['n'], $maxN, '#7B9EB8') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div>
      <?php if (!empty($emailsByYr)): ?>
      <div class="panel" style="margin-bottom:1rem">
        <div class="panel-title">Emails by school year</div>
        <?php
          $maxN = max(array_column($emailsByYr, 'n'));
          foreach ($emailsByYr as $row):
        ?>
          <div style="margin-bottom:.35rem">
            <div style="font-size:.78rem;color:#6b7280"><?= htmlspecialchars($row['yr']) ?></div>
            <?= bar((int)$row['n'], $maxN) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($emailsByWk)): ?>
      <div class="panel">
        <div class="panel-title">Emails — ingested by week</div>
        <?php
          $maxN = max(array_column($emailsByWk, 'n'));
          foreach ($emailsByWk as $row):
        ?>
          <div style="margin-bottom:.35rem">
            <div style="font-size:.78rem;color:#6b7280"><?= htmlspecialchars($row['week']) ?></div>
            <?= bar((int)$row['n'], $maxN, '#7B9EB8') ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── Low-hit queries ──────────────────────────────────────────────────── -->
  <h2>Knowledge Gaps — Low-Hit Queries</h2>
  <div class="panel">
    <p style="font-size:.85rem;color:#6b7280;margin-bottom:1rem">
      Queries returning fewer than 3 results. These topics are either not in the archive yet,
      or the information exists but isn't codified in a document someone could add to SharePoint.
    </p>
    <?php if (empty($lowHit)): ?>
      <p style="color:#6b7280;font-style:italic">No low-hit queries yet — keep searching!</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Query</th>
          <th style="text-align:center">Avg hits</th>
          <th style="text-align:center">Times asked</th>
          <th>Last asked</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lowHit as $row):
          $hits = (int)$row['avg_hits'];
          $badgeClass = $hits === 0 ? 'badge-red' : 'badge-amber';
          $lastAsked  = date('M j, Y', strtotime($row['last_asked'] ?? 'now'));
        ?>
        <tr>
          <td><?= htmlspecialchars($row['query']) ?></td>
          <td style="text-align:center">
            <span class="badge <?= $badgeClass ?>"><?= $hits ?></span>
          </td>
          <td style="text-align:center">
            <?php if ((int)$row['times_asked'] > 2): ?>
              <span class="badge badge-red"><?= $row['times_asked'] ?></span>
            <?php else: ?>
              <?= $row['times_asked'] ?>
            <?php endif; ?>
          </td>
          <td style="color:#6b7280"><?= $lastAsked ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

  <p class="refreshed">Generated <?= date('F j, Y \a\t g:i a') ?></p>

</div>
</body>
</html>

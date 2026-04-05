<?php
/**
 * test-explicit-cache-all-models.php
 *
 * Tests Gemini Context Cache API (explicit caching) against every model in
 * the coach5 dropdown.  For each model:
 *   1. POST to cachedContents — create an explicit cache from the Files API URI
 *   2. If created, run a test query using that cache name
 *   3. DELETE the cache (cleanup — minimises storage charges)
 *
 * This tells you:
 *   - Which models support explicit caching at all
 *   - Whether the known Tier-1 max_total_token_count=0 bug is still present
 *   - Per-model TTFB and cached-token confirmation
 *   - Whether the cleaned municipal code (~875k tokens) fits within the 1M limit
 *
 * Usage: https://yoursite.com/coach5/test-explicit-cache-all-models.php?secret=amentum2025
 *
 * NOTE: Each model test costs one cache creation + one generation call.
 *       A short TTL of 300s (5 min) is used to minimise storage charges.
 */

set_time_limit(300);

// ── Access guard ──────────────────────────────────────────────────────────────
if (($_GET['secret'] ?? '') !== 'amentum2025') {
    http_response_code(403);
    die('403 Forbidden');
}

// ── Paths ─────────────────────────────────────────────────────────────────────
$accountRoot   = dirname($_SERVER['DOCUMENT_ROOT'] ?? '/home/fikrttmy/public_html');
$secretsFile   = $accountRoot . '/.secrets/amentum_geminikey.php';
$cacheNameFile = $accountRoot . '/.secrets/gemini_cache_name.txt';
$mimeHintFile  = __DIR__ . '/Pasco-Municipal-Code-clean.mime';

if (!file_exists($secretsFile)) die('API key file missing');
require_once($secretsFile);
$key = trim($GEMINI_API_KEY);

// ── Read Files API URI ────────────────────────────────────────────────────────
$fileUri  = null;
$fileMime = 'text/plain';
if (file_exists($mimeHintFile)) {
    $fileMime = trim(file_get_contents($mimeHintFile)) ?: 'text/plain';
}
if (file_exists($cacheNameFile)) {
    $saved = trim(file_get_contents($cacheNameFile));
    if (!empty($saved) && strpos($saved, 'generativelanguage.googleapis.com') !== false) {
        // Check expiry
        $expiryFile = $cacheNameFile . '.expires';
        $expired = true;
        if (file_exists($expiryFile)) {
            $expired = (time() > (int)trim(file_get_contents($expiryFile)));
        }
        if (!$expired) $fileUri = $saved;
    }
}

// ── Models to test ────────────────────────────────────────────────────────────
$models = [
    'gemini-2.5-flash-lite',
    'gemini-2.5-flash',
    'gemini-2.5-pro',
    'gemini-3-flash-preview',
    'gemini-3-pro-preview',
    'gemini-2.0-flash',
    'gemini-2.0-flash-lite',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function api_post(string $url, array $payload, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        $timeout);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'err' => $err, 'data' => json_decode($body, true)];
}

function api_delete(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function badge(bool $ok, string $yes, string $no): string {
    $color = $ok ? '#1e8e3e' : '#c0392b';
    $bg    = $ok ? '#e6f4ea' : '#fce8e6';
    $label = $ok ? $yes      : $no;
    return "<span style='background:$bg;color:$color;border-radius:4px;padding:2px 8px;font-weight:600;font-size:0.85em'>$label</span>";
}

// ── Run tests ─────────────────────────────────────────────────────────────────
$results = [];

foreach ($models as $model) {
    $r = [
        'model'           => $model,
        'cache_http'      => null,
        'cache_ok'        => false,
        'cache_tokens'    => null,
        'cache_name'      => null,
        'cache_error'     => null,
        'cache_ms'        => null,
        'gen_http'        => null,
        'gen_ok'          => false,
        'gen_cached_tok'  => null,
        'gen_out_tok'     => null,
        'gen_ttfb_ms'     => null,
        'gen_reply'       => null,
        'gen_error'       => null,
        'delete_http'     => null,
        'cache_raw'       => null,
        'gen_raw'         => null,
    ];

    // ── Step 1: Create explicit context cache ─────────────────────────────────
    $createUrl = "https://generativelanguage.googleapis.com/v1beta/cachedContents?key=$key";
    $createPayload = [
        'model'    => "models/$model",
        'contents' => [[
            'role'  => 'user',
            'parts' => [['fileData' => ['mimeType' => $fileMime, 'fileUri' => $fileUri]]]
        ]],
        'ttl'      => '300s',   // 5-minute TTL — minimise storage charges
    ];

    $t0 = microtime(true);
    $cr = api_post($createUrl, $createPayload, 45);  // 45s max — bail fast on server errors
    $r['cache_ms']   = (int)round((microtime(true) - $t0) * 1000);
    $r['cache_http'] = $cr['code'];
    $r['cache_raw']  = $cr['body'];

    if ($cr['code'] === 200 && isset($cr['data']['name'])) {
        $r['cache_ok']     = true;
        $r['cache_name']   = $cr['data']['name'];
        $r['cache_tokens'] = $cr['data']['usageMetadata']['totalTokenCount'] ?? null;

        // ── Step 2: Test query against the cache ──────────────────────────────
        $genUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$key";
        $genPayload = [
            'cachedContent'    => $r['cache_name'],
            'contents'         => [[
                'role'  => 'user',
                'parts' => [['text' => 'What setback or permit requirements apply to a community garden under the Pasco Municipal Code? One short paragraph.']]
            ]],
            'generationConfig' => ['maxOutputTokens' => 300],
        ];

        $t1 = microtime(true);
        $gr = api_post($genUrl, $genPayload, 30);  // 30s max — we already have the cache
        $r['gen_ttfb_ms'] = (int)round((microtime(true) - $t1) * 1000);
        $r['gen_http']    = $gr['code'];
        $r['gen_raw']     = $gr['body'];

        if ($gr['code'] === 200 && isset($gr['data']['candidates'])) {
            $r['gen_ok']         = true;
            $usage               = $gr['data']['usageMetadata'] ?? [];
            $r['gen_cached_tok'] = $usage['cachedContentTokenCount'] ?? 0;
            $r['gen_out_tok']    = $usage['candidatesTokenCount']    ?? null;
            // Skip thoughtSignature parts — same logic as api-proxy.php
            $r['gen_reply'] = '';
            foreach (($gr['data']['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (isset($part['thoughtSignature'])) continue;
                if (!empty($part['text'])) { $r['gen_reply'] = $part['text']; break; }
            }
        } else {
            $r['gen_error'] = $gr['data']['error']['message'] ?? "HTTP {$gr['code']}";
        }

        // ── Step 3: Delete the test cache ─────────────────────────────────────
        $delUrl          = "https://generativelanguage.googleapis.com/v1beta/{$r['cache_name']}?key=$key";
        $dr              = api_delete($delUrl);
        $r['delete_http'] = $dr['code'];

    } else {
        $r['cache_error'] = $cr['err']
            ? "curl timeout/error: {$cr['err']}"
            : ($cr['data']['error']['message'] ?? "HTTP {$cr['code']}");
    }

    $results[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gemini Explicit Cache — All Models Test</title>
    <style>
        body  { font-family: 'Segoe UI', sans-serif; max-width: 1000px; margin: 30px auto; padding: 0 20px; color: #202124; }
        h1    { color: #1a73e8; }
        h2    { color: #444; margin-top: 2em; border-top: 1px solid #dadce0; padding-top: 0.8em; }
        table { border-collapse: collapse; width: 100%; margin: 1em 0; font-size: 0.88em; }
        th, td { border: 1px solid #dadce0; padding: 8px 12px; text-align: left; vertical-align: top; }
        th    { background: #e8f0fe; color: #1a56b0; font-weight: 600; }
        tr:nth-child(even) td { background: #f8f9fa; }
        .ok   { color: #1e8e3e; font-weight: 600; }
        .fail { color: #c0392b; font-weight: 600; }
        pre   { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 0.78em; white-space: pre-wrap; word-break: break-all; }
        .warn { background: #fef9e7; border: 1px solid #f5c518; border-radius: 6px; padding: 12px 16px; margin-bottom: 1em; }
        .model-header { font-family: monospace; font-size: 1em; }
    </style>
</head>
<body>

<h1>Gemini Explicit Context Cache — All Models Test</h1>

<?php if (!$fileUri): ?>
<div class="warn">
    <strong>⚠ No valid Files API URI found.</strong>
    Run <code>cache-create.php?secret=amentum2025</code> first to upload the municipal code.
</div>
<?php else: ?>
<p>Files API URI: <code><?= htmlspecialchars($fileUri) ?></code><br>
MIME: <code><?= htmlspecialchars($fileMime) ?></code></p>
<?php endif; ?>

<!-- ── Summary table ── -->
<h2>Summary</h2>
<table>
    <thead>
        <tr>
            <th>Model</th>
            <th>Cache Created?</th>
            <th>Tokens Cached</th>
            <th>Cache Create ms</th>
            <th>Query OK?</th>
            <th>Cached Tok in Query</th>
            <th>Out Tok</th>
            <th>TTFB ms</th>
            <th>Cache Deleted?</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $r): ?>
    <tr>
        <td class="model-header"><?= htmlspecialchars($r['model']) ?></td>
        <td><?= badge($r['cache_ok'], '✓ Yes', '✗ No') ?></td>
        <td><?= $r['cache_tokens'] !== null ? number_format($r['cache_tokens']) : '—' ?></td>
        <td><?= $r['cache_ms'] !== null ? $r['cache_ms'] . ' ms' : '—' ?></td>
        <td><?= $r['gen_ok'] ? badge(true, '✓ Yes', '') : ($r['cache_ok'] ? badge(false, '', '✗ No') : '—') ?></td>
        <td><?= $r['gen_cached_tok'] !== null ? number_format($r['gen_cached_tok']) : '—' ?></td>
        <td><?= $r['gen_out_tok']    !== null ? number_format($r['gen_out_tok'])    : '—' ?></td>
        <td><?= $r['gen_ttfb_ms']   !== null ? $r['gen_ttfb_ms'] . ' ms'           : '—' ?></td>
        <td>
            <?php if ($r['cache_name']): ?>
                <?= badge($r['delete_http'] === 200 || $r['delete_http'] === 204, '✓ ' . $r['delete_http'], '✗ ' . $r['delete_http']) ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- ── Per-model detail ── -->
<h2>Per-Model Detail</h2>

<?php foreach ($results as $r): ?>
<h3 class="model-header"><?= htmlspecialchars($r['model']) ?></h3>

<table>
    <tr><th>Step</th><th>HTTP</th><th>Result</th></tr>
    <tr>
        <td>1. Create cache</td>
        <td><?= $r['cache_http'] ?></td>
        <td>
            <?php if ($r['cache_ok']): ?>
                <span class="ok">✓ Created</span> — name: <code><?= htmlspecialchars($r['cache_name']) ?></code>,
                tokens: <?= number_format($r['cache_tokens']) ?>,
                ms: <?= $r['cache_ms'] ?>
            <?php else: ?>
                <span class="fail">✗ Failed</span> — <?= htmlspecialchars($r['cache_error'] ?? 'Unknown error') ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($r['cache_ok']): ?>
    <tr>
        <td>2. Test query</td>
        <td><?= $r['gen_http'] ?></td>
        <td>
            <?php if ($r['gen_ok']): ?>
                <span class="ok">✓ OK</span> —
                cachedTok: <?= number_format($r['gen_cached_tok']) ?>,
                outTok: <?= number_format($r['gen_out_tok']) ?>,
                TTFB: <?= $r['gen_ttfb_ms'] ?>ms
                <br><em><?= nl2br(htmlspecialchars(substr($r['gen_reply'], 0, 300))) ?>…</em>
            <?php else: ?>
                <span class="fail">✗ Failed</span> — <?= htmlspecialchars($r['gen_error'] ?? 'Unknown error') ?>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td>3. Delete cache</td>
        <td><?= $r['delete_http'] ?></td>
        <td>
            <?php if ($r['delete_http'] === 200 || $r['delete_http'] === 204): ?>
                <span class="ok">✓ Deleted</span>
            <?php else: ?>
                <span class="fail">✗ Failed</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endif; ?>
</table>

<!-- Raw API responses (collapsed via details) -->
<details>
    <summary style="cursor:pointer;color:#1a73e8;margin:6px 0">Raw: Cache Create Response</summary>
    <pre><?= htmlspecialchars(json_encode(json_decode($r['cache_raw']), JSON_PRETTY_PRINT)) ?></pre>
</details>
<?php if ($r['gen_raw']): ?>
<details>
    <summary style="cursor:pointer;color:#1a73e8;margin:6px 0">Raw: Generation Response</summary>
    <pre><?= htmlspecialchars(json_encode(json_decode($r['gen_raw']), JSON_PRETTY_PRINT)) ?></pre>
</details>
<?php endif; ?>

<?php endforeach; ?>

<hr>
<p style="color:#888;font-size:0.85em">
    Test complete. All caches with TTL=300s will auto-expire within 5 minutes if deletion failed.<br>
    Known issues to watch for: <em>max_total_token_count=0</em> error (Tier-1 bug), model does not support caching, token count exceeds 1M limit.
</p>
</body>
</html>

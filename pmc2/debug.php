<?php
/**
 * pmc2/debug.php  — diagnostic page (DELETE or password-protect after debugging)
 * Visit: https://psd1.net/pmc2/debug.php
 */
header('Content-Type: text/plain; charset=utf-8');

// ── 1. Environment ────────────────────────────────────────────────────────────
echo "=== PMC2 DIAGNOSTICS ===\n";
echo "Date/Time : " . date('Y-m-d H:i:s T') . "\n";
echo "PHP       : " . PHP_VERSION . "\n";
echo "SAPI      : " . PHP_SAPI . "\n";
echo "Doc Root  : " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";
echo "Script    : " . __FILE__ . "\n\n";

// ── 2. Secrets file ───────────────────────────────────────────────────────────
$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/claudekey.php';
echo "=== SECRETS FILE ===\n";
echo "Expected path : $secretsFile\n";
echo "Exists        : " . (file_exists($secretsFile) ? "YES" : "NO") . "\n";

$apiKey = null;
if (file_exists($secretsFile)) {
    $secrets = require $secretsFile;
    if (is_array($secrets)) {
        $apiKey = $secrets['ANTHROPIC_API_KEY'] ?? null;
        echo "Format        : return-array style\n";
    } else {
        // Variable-assignment style: $ANTHROPIC_API_KEY = '...';
        $apiKey = $ANTHROPIC_API_KEY ?? null;
        echo "Format        : variable-assignment style (or unknown, require returned: " . var_export($secrets, true) . ")\n";
    }
    if ($apiKey) {
        echo "API key       : " . substr($apiKey, 0, 12) . "... (length " . strlen($apiKey) . ")\n";
    } else {
        echo "API key       : NOT FOUND in file\n";
    }
} else {
    echo "CANNOT READ secrets file — check path\n";
}
echo "\n";

// ── 3. PMC file candidates ────────────────────────────────────────────────────
echo "=== PMC FILE CANDIDATES ===\n";
$candidates = [
    __DIR__ . '/Pasco-Municipal-Code-clean.txt',
    __DIR__ . '/Pasco-Municipal-Code-clean.html',
    __DIR__ . '/../pmc1/Pasco-Municipal-Code-clean.txt',
    __DIR__ . '/../pmc1/Pasco-Municipal-Code-clean.html',
];
$pmcText = null;
foreach ($candidates as $path) {
    $exists = file_exists($path);
    $size   = $exists ? number_format(filesize($path)) . ' bytes' : 'n/a';
    echo ($exists ? "[FOUND] " : "[  --  ] ") . $path . "  ($size)\n";
    if ($exists && $pmcText === null) {
        $raw = file_get_contents($path);
        if ($raw !== false) {
            if (str_ends_with($path, '.html')) {
                $raw = preg_replace('/\s+/', ' ', strip_tags($raw));
                echo "         → stripped HTML; cleaned length = " . number_format(strlen($raw)) . "\n";
            }
            $pmcText = $raw;
        }
    }
}
if ($pmcText === null) {
    echo "\nWARNING: No PMC file found — Claude will respond without code context.\n";
} else {
    // Rough token estimate (1 token ≈ 4 chars for English)
    $approxTokens = number_format(intdiv(strlen($pmcText), 4));
    echo "\nUsing: first FOUND file above (approx $approxTokens tokens)\n";
}
echo "\n";

// ── 4. curl availability ──────────────────────────────────────────────────────
echo "=== CURL ===\n";
echo "curl loaded          : " . (function_exists('curl_init') ? "YES" : "NO") . "\n";
if (function_exists('curl_version')) {
    $cv = curl_version();
    echo "curl version         : " . $cv['version'] . "\n";
    echo "SSL version          : " . $cv['ssl_version'] . "\n";
    echo "CURLOPT_WRITEFUNCTION  : " . (defined('CURLOPT_WRITEFUNCTION')  ? "YES (const=" . CURLOPT_WRITEFUNCTION  . ")" : "NO — streaming broken") . "\n";
echo "CURLOPT_PROGRESSFUNC  : " . (defined('CURLOPT_PROGRESSFUNCTION') ? "YES" : "NO — heartbeat unavailable") . "\n";
}
echo "output_buffering       : " . ini_get('output_buffering') . "\n";
echo "implicit_flush         : " . ini_get('implicit_flush') . "\n";
echo "zlib.output_compression: " . ini_get('zlib.output_compression') . "\n";
echo "\n";

// ── 5. Minimal Anthropic API test ─────────────────────────────────────────────
echo "=== ANTHROPIC API TEST ===\n";
if (!$apiKey) {
    echo "SKIPPED — no API key available.\n";
} elseif (!function_exists('curl_init')) {
    echo "SKIPPED — curl not available.\n";
} else {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 20,
        'messages'   => [['role' => 'user', 'content' => 'Reply with exactly one word: PONG']],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ]);

    $body     = curl_exec($ch);
    $errno    = curl_errno($ch);
    $errstr   = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP status : $httpCode\n";
    if ($errno) {
        echo "curl error  : $errno — $errstr\n";
    } else {
        $resp = json_decode($body, true);
        if ($httpCode === 200 && isset($resp['content'][0]['text'])) {
            echo "Response    : " . $resp['content'][0]['text'] . "\n";
            echo "Result      : SUCCESS — API key is valid and model responds\n";
        } else {
            $errMsg = $resp['error']['message'] ?? $body;
            echo "Error body  : $errMsg\n";
            echo "Result      : FAILED\n";
        }
    }
}
echo "\n";

// ── 6. json_encode sanity check ───────────────────────────────────────────────
echo "=== JSON ENCODE ===\n";
if ($pmcText !== null) {
    $sample   = substr($pmcText, 0, 500);
    $encoded  = json_encode(['text' => $sample]);
    echo "PMC sample json_encode : " . ($encoded !== false ? "OK" : "FAILED — invalid bytes in file") . "\n";
    // Full PMC
    $fullEnc = json_encode(['text' => $pmcText], JSON_UNESCAPED_UNICODE);
    echo "Full PMC json_encode   : " . ($fullEnc !== false ? "OK (" . number_format(strlen($fullEnc)) . " bytes)" : "FAILED — file contains characters that break json_encode") . "\n";
} else {
    echo "SKIPPED — no PMC text loaded.\n";
}
echo "\n";

// ── 7. Stream test link ───────────────────────────────────────────────────────
echo "=== STREAMING TEST ===\n";
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'psd1.net';
echo "Open this URL in a browser tab — you should see words appear one per second:\n";
echo "  {$proto}://{$host}/pmc2/api-proxy.php?stream=test\n";
echo "(If page loads instantly with all words at once, LiteSpeed is buffering output.)\n\n";

// ── 8. Last debug log entries ─────────────────────────────────────────────────
echo "=== LAST DEBUG LOG (claude_debug.log) ===\n";
$debugLog = __DIR__ . '/claude_debug.log';
if (file_exists($debugLog)) {
    $lines = file($debugLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $tail  = array_slice($lines, -40);
    echo implode("\n", $tail) . "\n";
} else {
    echo "(no log yet — send a chat message first, then refresh this page)\n";
}
echo "\n";
echo "=== END ===\n";

<?php
/**
 * update_models.php — Automatically update model_config.json
 *
 * This script probes the Anthropic API to verify which models are currently
 * available, then updates model_config.json with the best working model for
 * each tier (haiku, sonnet, opus).
 *
 * Usage:
 *   - Cron job (recommended):  Run daily or weekly via cron
 *       0 6 * * 1  php /path/to/update_models.php >> /path/to/update_models.log 2>&1
 *
 *   - Manual:  php update_models.php
 *
 *   - Web (admin only):  https://your-site.com/update_models.php?key=YOUR_KEY
 *     The key must match DASHBOARD_PASS in your secrets file.
 *
 * How it works:
 *   1. Reads the current model_config.json
 *   2. For each tier, sends a minimal API request ("ping") to test the primary model
 *   3. If the primary works → no change
 *   4. If the primary fails with a model error → tries each fallback in order
 *   5. If a fallback works → promotes it to primary, logs the change
 *   6. Writes updated config back to model_config.json
 *
 * Safety:
 *   - Only writes if something actually changed
 *   - Uses file locking to prevent race conditions with api-proxy.php
 *   - Logs all activity to stdout (or web output) for audit trail
 *   - Each probe uses max_tokens=1 to minimize cost (~0 cost per run)
 */

// ============================================
// CONFIGURATION
// ============================================

$configPath = __DIR__ . '/model_config.json';
$isWeb = php_sapi_name() !== 'cli';

// ============================================
// ACCESS CONTROL
// ============================================

if ($isWeb) {
    header('Content-Type: text/plain; charset=utf-8');

    // Load secrets for web key verification
    $accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
    $secretsFile = $accountRoot . '/.secrets/claudekey.php';
    if (!is_readable($secretsFile)) {
        http_response_code(500);
        echo "Error: Cannot read secrets file.\n";
        exit;
    }
    $secrets = require $secretsFile;
    $validKey = $secrets['DASHBOARD_PASS'] ?? null;

    $providedKey = $_GET['key'] ?? '';
    if (!$validKey || $providedKey !== $validKey) {
        http_response_code(403);
        echo "Forbidden. Provide ?key=YOUR_DASHBOARD_PASS\n";
        exit;
    }
}

// ============================================
// LOAD API KEY
// ============================================

$accountRoot = $accountRoot ?? dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$secretsFile = $secretsFile ?? $accountRoot . '/.secrets/claudekey.php';

if (!is_readable($secretsFile)) {
    // CLI fallback: try relative to this script
    $secretsFile = dirname(__DIR__, 2) . '/.secrets/claudekey.php';
}

if (!is_readable($secretsFile)) {
    output("ERROR: Cannot find secrets file. Tried: $secretsFile");
    exit(1);
}

$secrets = require $secretsFile;
$apiKey = $secrets['ANTHROPIC_API_KEY'] ?? null;

if (!$apiKey) {
    output("ERROR: ANTHROPIC_API_KEY not found in secrets file.");
    exit(1);
}

// ============================================
// LOAD CURRENT CONFIG
// ============================================

output("=== Model Config Updater ===");
output("Time: " . gmdate('Y-m-d H:i:s') . " UTC");
output("Config: $configPath");
output("");

if (!is_readable($configPath)) {
    output("WARNING: model_config.json not found. Will create from defaults.");
    $config = getDefaultConfig();
} else {
    $raw = file_get_contents($configPath);
    $config = json_decode($raw, true);
    if (!is_array($config) || !isset($config['tiers'])) {
        output("WARNING: model_config.json is corrupt. Will recreate from defaults.");
        $config = getDefaultConfig();
    }
}

// ============================================
// PROBE EACH TIER
// ============================================

$changed = false;
$results = [];

foreach ($config['tiers'] as $tier => $tierConfig) {
    $primary = $tierConfig['primary'];
    $fallbacks = $tierConfig['fallbacks'] ?? [];

    output("--- Tier: $tier ---");
    output("  Primary: $primary");

    // Test the primary model
    $result = probeModel($primary, $apiKey);

    if ($result['ok']) {
        output("  Status: OK (primary works)");
        $results[$tier] = ['status' => 'ok', 'model' => $primary];
        continue;
    }

    output("  Primary FAILED: {$result['error']}");

    if ($result['is_model_error'] && !empty($fallbacks)) {
        output("  Trying fallbacks...");

        $promoted = false;
        foreach ($fallbacks as $i => $fallback) {
            output("    Fallback [$i]: $fallback");
            $fbResult = probeModel($fallback, $apiKey);

            if ($fbResult['ok']) {
                output("    -> SUCCESS! Promoting '$fallback' to primary for tier '$tier'");

                // Promote: move working model to primary, keep remaining fallbacks
                $newFallbacks = array_values(array_filter($fallbacks, function($m) use ($fallback) {
                    return $m !== $fallback;
                }));
                // Add the old primary to the end of fallbacks (it might come back)
                $newFallbacks[] = $primary;

                $config['tiers'][$tier]['primary'] = $fallback;
                $config['tiers'][$tier]['fallbacks'] = $newFallbacks;
                $changed = true;
                $promoted = true;

                $results[$tier] = ['status' => 'promoted', 'model' => $fallback, 'was' => $primary];
                break;
            } else {
                output("    -> FAILED: {$fbResult['error']}");
            }
        }

        if (!$promoted) {
            output("  WARNING: ALL models failed for tier '$tier'!");
            $results[$tier] = ['status' => 'all_failed', 'model' => $primary];
        }
    } else {
        // Not a model error (e.g. rate limit, auth error) — don't change config
        output("  Not a model error — keeping current primary.");
        $results[$tier] = ['status' => 'non_model_error', 'model' => $primary, 'error' => $result['error']];
    }
}

// ============================================
// WRITE UPDATED CONFIG
// ============================================

output("");

if ($changed) {
    $config['_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $config['_updated_by'] = 'update_models.php';

    $fp = fopen($configPath, 'c+');
    if (!$fp) {
        output("ERROR: Cannot open $configPath for writing.");
        exit(1);
    }
    if (!flock($fp, LOCK_EX)) {
        output("ERROR: Cannot lock $configPath.");
        fclose($fp);
        exit(1);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);

    output("Config UPDATED and saved.");
} else {
    output("No changes needed — all primaries are working.");
}

// ============================================
// SUMMARY
// ============================================

output("");
output("=== Summary ===");
foreach ($results as $tier => $r) {
    $line = "  $tier: {$r['status']} -> {$r['model']}";
    if (isset($r['was'])) {
        $line .= " (was: {$r['was']})";
    }
    if (isset($r['error'])) {
        $line .= " (error: {$r['error']})";
    }
    output($line);
}
output("");
output("Done.");

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Send a minimal API request to test if a model is available.
 * Uses max_tokens=1 to minimize cost (essentially free).
 */
function probeModel(string $model, string $apiKey): array
{
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 1,
        'messages' => [
            ['role' => 'user', 'content' => 'Hi']
        ]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => "cURL: $curlError", 'is_model_error' => false];
    }

    $data = json_decode($response, true);

    // Success
    if ($httpCode === 200) {
        return ['ok' => true, 'error' => null, 'is_model_error' => false];
    }

    // Check if this is specifically a model-not-found error
    $errorType = $data['error']['type'] ?? '';
    $errorMsg = strtolower($data['error']['message'] ?? '');
    $isModelError = ($httpCode === 400 || $httpCode === 404)
        && ($errorType === 'invalid_request_error' || $errorType === 'not_found_error')
        && strpos($errorMsg, 'model') !== false;

    // Rate limit or overloaded — model probably works, just busy
    if ($httpCode === 429 || $httpCode === 529) {
        return ['ok' => true, 'error' => null, 'is_model_error' => false];
    }

    return [
        'ok' => false,
        'error' => "HTTP $httpCode: " . ($data['error']['message'] ?? 'unknown'),
        'is_model_error' => $isModelError,
    ];
}

/**
 * Default config if model_config.json is missing or corrupt.
 */
function getDefaultConfig(): array
{
    return [
        '_comment' => 'Model configuration for Claude API proxy.',
        'tiers' => [
            'haiku' => [
                'primary' => 'claude-haiku-4-5-20251001',
                'fallbacks' => ['claude-haiku-4-5', 'claude-3-haiku-20240307'],
                'pricing' => ['input_per_mtok' => 1.00, 'output_per_mtok' => 5.00],
            ],
            'sonnet' => [
                'primary' => 'claude-sonnet-4-6',
                'fallbacks' => ['claude-sonnet-4-5-20250929', 'claude-sonnet-4-5', 'claude-sonnet-4-20250514'],
                'pricing' => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
            ],
            'opus' => [
                'primary' => 'claude-opus-4-6',
                'fallbacks' => ['claude-opus-4-5-20251101', 'claude-opus-4-5', 'claude-opus-4-1-20250805'],
                'pricing' => ['input_per_mtok' => 5.00, 'output_per_mtok' => 25.00],
            ],
        ],
    ];
}

/**
 * Output a line to stdout (CLI) or echo (web).
 */
function output(string $msg): void
{
    echo $msg . "\n";
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

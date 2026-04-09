<?php
/**
 * PMC3 OpenAI Proxy — Pasco Municipal Code AI Reference
 * Mirrors pmc1 UI contract while routing to OpenAI Responses API.
 */

declare(strict_types=1);
set_time_limit(300);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function ensure_query_log_dir(string $baseDir): string
{
    $dir = $baseDir . '/query_logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    return $dir;
}

function resolve_secrets_path(string $repoDir, ?string $envOverride): string
{
    if (!empty($envOverride)) {
        return $envOverride;
    }

    $accountRoot = dirname($_SERVER['DOCUMENT_ROOT'] ?? $repoDir);
    $candidates = [
        $repoDir . '/.secrets/chatgptkey.php',
        dirname($repoDir) . '/.secrets/chatgptkey.php',
        dirname(dirname($repoDir)) . '/.secrets/chatgptkey.php',
        $accountRoot . '/.secrets/chatgptkey.php',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return $accountRoot . '/.secrets/chatgptkey.php';
}

function flatten_text_part($part): string
{
    if (!is_array($part)) {
        return '';
    }
    if (isset($part['text']) && is_string($part['text'])) {
        return $part['text'];
    }
    return '';
}


function extract_text_from_event_response(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text']) && $response['output_text'] !== '') {
        return $response['output_text'];
    }
    $chunks = [];
    foreach (($response['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $chunks[] = (string)($content['text'] ?? '');
            }
        }
    }
    return trim(implode('', $chunks));
}

function extract_reply_from_response(array $decoded): string
{
    if (isset($decoded['output_text']) && is_string($decoded['output_text']) && $decoded['output_text'] !== '') {
        return $decoded['output_text'];
    }

    $chunks = [];
    foreach (($decoded['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') {
            continue;
        }
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') {
                $chunks[] = (string)($content['text'] ?? '');
            }
        }
    }

    return trim(implode('', $chunks));
}

function extract_latest_user_text(array $messages): string
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $m = $messages[$i] ?? null;
        if (!is_array($m) || (($m['role'] ?? '') !== 'user')) {
            continue;
        }
        $parts = $m['parts'] ?? [];
        $texts = [];
        foreach ($parts as $p) {
            if (isset($p['text']) && is_string($p['text'])) {
                $texts[] = $p['text'];
            }
        }
        $joined = trim(implode(' ', $texts));
        if ($joined !== '') {
            return $joined;
        }
    }
    return '';
}

function build_pmc_context(string $pmcText, string $queryText, int $maxChars): string
{
    if ($pmcText === '') return '';

    if (mb_strlen($pmcText) <= $maxChars) {
        return $pmcText;
    }

    // Split into rough paragraphs and rank by keyword overlap with the latest user query.
    $paragraphs = preg_split("/\n\s*\n/u", $pmcText) ?: [$pmcText];
    $query = mb_strtolower($queryText);
    preg_match_all('/[a-z0-9]{3,}/iu', $query, $qmatches);
    $terms = array_slice(array_values(array_unique($qmatches[0] ?? [])), 0, 30);

    $scored = [];
    foreach ($paragraphs as $idx => $para) {
        $p = trim($para);
        if ($p === '') continue;
        $lp = mb_strtolower($p);
        $score = 0;
        foreach ($terms as $t) {
            if (mb_strpos($lp, mb_strtolower($t)) !== false) $score++;
        }
        // Prefer paragraphs that contain explicit section symbols/references.
        if (preg_match('/§\s*\d+\.\d+\.\d+/u', $p)) $score += 2;
        $scored[] = ['idx' => $idx, 'score' => $score, 'text' => $p];
    }

    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) return $a['idx'] <=> $b['idx'];
        return $b['score'] <=> $a['score'];
    });

    $picked = [];
    $used = 0;
    foreach ($scored as $row) {
        if ($used >= $maxChars) break;
        $chunk = $row['text'];
        if ($chunk === '') continue;
        $remaining = $maxChars - $used;
        $chunkLen = mb_strlen($chunk);
        if ($chunkLen > $remaining) {
            $chunk = mb_substr($chunk, 0, max(0, $remaining - 1));
            $chunkLen = mb_strlen($chunk);
        }
        if ($chunkLen <= 0) continue;
        $picked[] = $chunk;
        $used += $chunkLen + 2;
        if (count($picked) >= 40) break;
    }

    if (empty($picked)) {
        return mb_substr($pmcText, 0, $maxChars);
    }

    return implode("\n\n", $picked);
}

function corpus_char_budget_for_model(string $model): int
{
    return match ($model) {
        'gpt-4.1' => 16_000,
        'gpt-4.1-mini' => 10_000,
        'gpt-5' => 22_000,
        'gpt-5-mini' => 12_000,
        'gpt-5-nano' => 8_000,
        default => 10_000,
    };
}

function output_token_budget_for_model(string $model): int
{
    return match ($model) {
        'gpt-4.1' => 1600,
        'gpt-4.1-mini' => 1200,
        'gpt-5' => 2200,
        'gpt-5-mini' => 1800,
        'gpt-5-nano' => 900,
        default => 1200,
    };
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    send_json(['error' => 'Invalid JSON payload.'], 400);
}

if (($data['action'] ?? '') === 'log_query') {
    $sessionId = preg_replace('/[^a-z0-9_\-]/i', '_', (string)($data['session_id'] ?? 'unknown'));
    $logText = (string)($data['log'] ?? '');

    $logDir = ensure_query_log_dir(__DIR__);
    $logFile = $logDir . '/' . date('Y-m') . '.txt';
    $entry = '--- ' . date('Y-m-d H:i:s') . ' | ' . $sessionId . " ---\n" . $logText . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);

    if (isset($data['ttfb_ms']) && is_numeric($data['ttfb_ms'])) {
        $usageLine = date('Y-m-d H:i:s') . ' | ' . $sessionId . ' | TTFB:' . (int)$data['ttfb_ms'] . "ms\n";
        file_put_contents(__DIR__ . '/openai_usage.log', $usageLine, FILE_APPEND);
    }

    send_json(['success' => true]);
}

$secretsPath = resolve_secrets_path(__DIR__, getenv('CHATGPT_SECRETS_PATH') ?: null);
if (!file_exists($secretsPath)) {
    send_json(['error' => 'Secrets file not found', 'details' => $secretsPath], 500);
}

$secrets = require $secretsPath;
$apiKey = trim((string)($secrets['OPENAI_API_KEY'] ?? ''));
if ($apiKey === '') {
    send_json(['error' => 'OPENAI_API_KEY missing in secrets file.'], 500);
}

$modelMap = [
    // Stable aliases (to reduce merge/deploy drift across branches):
    'gpt-5' => 'gpt-5',
    'gpt-5-mini' => 'gpt-5-mini',
    'gpt-5-nano' => 'gpt-5-nano',
    // Accept legacy/alternate IDs seen in prior branch revisions:
    'gpt-5.4' => 'gpt-5',
    'gpt-5.4-mini' => 'gpt-5-mini',
    'gpt-5.4-nano' => 'gpt-5-nano',
    'gpt-4.1' => 'gpt-4.1',
    'gpt-4.1-mini' => 'gpt-4.1-mini',
];
$requested = (string)($data['model'] ?? 'gpt-5-mini');
$model = $modelMap[$requested] ?? 'gpt-5-mini';

$pmcSourcePath = dirname(__DIR__) . '/pmc1/Pasco-Municipal-Code.html';
$pmcText = '';
if (file_exists($pmcSourcePath)) {
    $rawPmc = (string)file_get_contents($pmcSourcePath);
    $pmcText = html_entity_decode(strip_tags($rawPmc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$systemPrefix = "You are the Pasco Municipal Code AI Reference. Use only the provided Pasco Municipal Code text as your primary authority. Cite section numbers in this style: §X.XX.XXX whenever possible. If the answer is not present in the code, say so clearly and suggest where to verify.";
$userSystem = trim((string)($data['system'] ?? ''));
$fullSystem = $systemPrefix;
if ($userSystem !== '') {
    $fullSystem .= "\n\nAdditional system guidance:\n" . $userSystem;
}
if ($pmcText !== '') {
    $latestUserText = extract_latest_user_text($data['messages'] ?? []);
    $maxCorpusChars = corpus_char_budget_for_model($model);
    $pmcContext = build_pmc_context($pmcText, $latestUserText, $maxCorpusChars);
    $fullSystem .= "\n\nPasco Municipal Code (retrieved reference excerpt):\n" . $pmcContext;
}

$input = [[
    'role' => 'system',
    'content' => [
        ['type' => 'input_text', 'text' => $fullSystem],
    ],
]];

$messages = $data['messages'] ?? [];
if (is_array($messages)) {
    // Keep recent chat context but cap message payload to avoid TPM spikes.
    while (strlen(json_encode($messages)) > 24_000 && count($messages) > 2) {
        array_shift($messages);
    }
}
foreach ($messages as $message) {
    if (!is_array($message)) {
        continue;
    }
    $role = (($message['role'] ?? '') === 'assistant' || ($message['role'] ?? '') === 'model') ? 'assistant' : 'user';
    $content = [];
    $textPartType = $role === 'assistant' ? 'output_text' : 'input_text';

    foreach (($message['parts'] ?? []) as $part) {
        $text = flatten_text_part($part);
        if ($text !== '') {
            $content[] = ['type' => $textPartType, 'text' => $text];
        }

        if ($role === 'user' && isset($part['inlineData']['data'], $part['inlineData']['mimeType'])) {
            $mime = (string)$part['inlineData']['mimeType'];
            $base64 = (string)$part['inlineData']['data'];
            if ($mime !== '' && $base64 !== '') {
                $content[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:' . $mime . ';base64,' . $base64,
                ];
            }
        }
    }

    if (!empty($content)) {
        $input[] = ['role' => $role, 'content' => $content];
    }
}

$payload = [
    'model' => $model,
    'input' => $input,
    'max_output_tokens' => output_token_budget_for_model($model),
];
if (strpos($model, 'gpt-5') === 0) {
    $payload['reasoning'] = ['effort' => 'medium'];
}

$isStream = (($_GET['stream'] ?? '') === '1');

if ($isStream) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    while (ob_get_level()) {
        ob_end_flush();
    }

    $payload['stream'] = true;
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/event-stream',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

    $cachedInputTokens = 0;
    $inputTokens = 0;
    $outputTokens = 0;
    $isTruncated = false;
    $sentAnyDelta = false;

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$cachedInputTokens, &$inputTokens, &$outputTokens, &$isTruncated, &$sentAnyDelta) {
        static $buffer = '';
        $buffer .= $chunk;
        $lines = explode("\n", $buffer);
        $buffer = array_pop($lines);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, 'data: ') !== 0) {
                continue;
            }
            $json = substr($trimmed, 6);
            if ($json === '[DONE]') {
                continue;
            }

            $event = json_decode($json, true);
            if (!is_array($event)) {
                continue;
            }

            $type = (string)($event['type'] ?? '');

            if ($type === 'error' || $type === 'response.error') {
                $message = (string)($event['error']['message'] ?? 'OpenAI stream error');
                echo 'data: ' . json_encode(['error' => ['message' => $message]]) . "\n\n";
                flush();
                continue;
            }

            if ($type === 'response.output_text.delta') {
                $delta = (string)($event['delta'] ?? '');
                if ($delta !== '') {
                    $sentAnyDelta = true;
                    echo 'data: ' . json_encode(['text' => $delta]) . "\n\n";
                    flush();
                }
                continue;
            }

            if ($type === 'response.completed') {
                $responseObj = $event['response'] ?? [];
                $usage = $responseObj['usage'] ?? [];
                $details = $usage['input_tokens_details'] ?? [];
                $inputTokens = (int)($usage['input_tokens'] ?? 0);
                $outputTokens = (int)($usage['output_tokens'] ?? 0);
                $cachedInputTokens = (int)($details['cached_tokens'] ?? 0);
                $status = (string)($responseObj['status'] ?? '');
                $isTruncated = ($status === 'incomplete');

                $finalText = extract_text_from_event_response(is_array($responseObj) ? $responseObj : []);
                if (!$sentAnyDelta && $finalText !== '') {
                    echo 'data: ' . json_encode(['text' => $finalText]) . "\n\n";
                    flush();
                }
            }
        }

        return strlen($chunk);
    });

    curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo 'data: ' . json_encode(['error' => $curlError]) . "\n\n";
    }

    echo 'data: ' . json_encode([
        'meta' => [
            'cachedTokens' => $cachedInputTokens,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'truncated' => $isTruncated,
        ],
    ]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    send_json(['error' => 'Network error: ' . $curlError], 500);
}

$decoded = json_decode((string)$response, true);
if ($httpCode < 200 || $httpCode >= 300) {
    $error = $decoded['error']['message'] ?? $response;
    send_json(['error' => $error], $httpCode ?: 500);
}

$reply = extract_reply_from_response($decoded ?? []);
$finishReason = ($decoded['status'] ?? '') === 'incomplete' ? 'MAX_OUTPUT_TOKENS' : 'STOP';

send_json([
    'reply' => $reply,
    'finish_reason' => $finishReason,
    'usage' => $decoded['usage'] ?? null,
]);

<?php
/**
 * LEO Legal Reference — Streaming Proxy
 *
 * Derivative of rcw-wac/api-proxy.php. System prompt tuned for law enforcement.
 * Uses the same Supabase backend and RcwWacProxy class from rcw-wac/src/.
 *
 * Request:  POST api-proxy.php?stream=1
 * Body:     { "query": "...", "corpus": "rcw|wac|state|federal|both", "messages": [...] }
 */

set_time_limit(120);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

// Shared proxy class lives in the sibling rcw-wac folder
require_once __DIR__ . '/../rcw-wac/src/RcwWacProxy.php';
use RcwWac\RcwWacProxy;
use RcwWac\EmbeddingException;
use RcwWac\SupabaseException;

// ── Secrets ───────────────────────────────────────────────────────────────────

$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/rcwkey.php';

function load_secrets(string $path): array {
    if (!file_exists($path)) return [];
    $result = require $path;
    if (is_array($result)) return $result;
    return [
        'OPENAI_API_KEY'    => $OPENAI_API_KEY    ?? '',
        'SUPABASE_URL'      => $SUPABASE_URL       ?? '',
        'SUPABASE_ANON_KEY' => $SUPABASE_ANON_KEY  ?? '',
        'ANTHROPIC_API_KEY' => $ANTHROPIC_API_KEY  ?? '',
    ];
}

// ── System prompt ─────────────────────────────────────────────────────────────

define('SYSTEM_PROMPT', <<<'PROMPT'
You are a legal reference assistant for Washington State law enforcement officers, supervisors, and administrators. You help users understand Washington and federal law as it applies to law enforcement.

Sources covered:
- RCW  — Revised Code of Washington (state statute enacted by the Legislature)
- WAC  — Washington Administrative Code (state agency rules)
- USC  — United States Code (federal statute enacted by Congress)
- CFR  — Code of Federal Regulations (federal agency rules)

Key areas in this database:
- Use of force: RCW 10.116 (use of force standards), RCW 10.120 (officer intervention and de-escalation), RCW 9A.16 (lawful use of force defenses)
- Criminal procedure: RCW Title 10 (arrest, search and seizure, warrants, bail)
- Criminal code: RCW Title 9A (Washington Criminal Code — assault, theft, weapons, etc.), RCW Title 9
- Officer authority and duties: RCW 10.31 (arrest authority), RCW 10.31.100 (domestic violence mandatory arrest)
- Officer certification: RCW 43.101, WAC 139 (CJTC — training and certification requirements)
- Municipal police authority: RCW Title 35A (code cities — applicable to Pasco), RCW Title 36 (counties)
- Traffic enforcement: RCW Title 46
- Public records: RCW 42.56 (disclosure obligations and exemptions for law enforcement records)

When answering:
- Cite every specific section you draw from (e.g., "RCW 10.116.020", "WAC 139-12-030").
- Distinguish statute (RCW/USC) from administrative rule (WAC/CFR).
- For use-of-force questions, apply the RCW 10.116/10.120 framework from the 2021 Washington police reform laws.
- When federal constitutional standards apply (4th, 5th, 14th Amendment), note them even if the specific case law is not in the retrieved sections.
- Write in plain language; define legal terms on first use.
- If a retrieved section only partially answers the question, say so and identify what is missing.
- Do not speculate about sections not provided in the context.

IMPORTANT: This tool provides general legal information only — not legal advice. For specific operational or legal situations, officers should consult their department legal advisor, city attorney, or the prosecuting attorney's office.

The following law sections were retrieved as relevant to the question:

{CONTEXT}
PROMPT);

define('MAX_OUTPUT_TOKENS', 2000);

// ── Stream handler ────────────────────────────────────────────────────────────

function start_sse(): void {
    @ini_set('output_buffering',        '0');
    @ini_set('implicit_flush',          '1');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('X-LiteSpeed-Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Encoding: identity');
    header('Connection: keep-alive');
    if (function_exists('apache_setenv')) { apache_setenv('no-gzip', '1'); }
    echo ": init\n\n";
    flush();
}

function sse(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); flush();
}

function sse_error(string $msg): void {
    sse(['error' => $msg]);
    echo "data: [DONE]\n\n";
    flush();
}

// ── Route ─────────────────────────────────────────────────────────────────────

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// ?stats=1  — live DB counts per corpus (calls rcw_wac_stats() RPC)
if (isset($_GET['stats'])) {
    header('Content-Type: application/json');
    $secrets = load_secrets($secretsFile);
    $url = rtrim($secrets['SUPABASE_URL'] ?? '', '/') . '/rest/v1/rpc/rcw_wac_stats';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '               . ($secrets['SUPABASE_ANON_KEY'] ?? ''),
            'Authorization: Bearer ' . ($secrets['SUPABASE_ANON_KEY'] ?? ''),
        ],
        CURLOPT_POSTFIELDS => '{}',
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo ($result && $httpCode === 200) ? $result : json_encode(['error' => "HTTP $httpCode"]);
    exit;
}

// ?prompt=1 — return the system prompt template as JSON
if (isset($_GET['prompt'])) {
    header('Content-Type: application/json');
    echo json_encode(['prompt' => SYSTEM_PROMPT]);
    exit;
}

// ?catalog=rcw|wac|usc|cfr — titles/chapters ingested for that corpus
if (isset($_GET['catalog'])) {
    header('Content-Type: application/json');
    $corp = $_GET['catalog'];
    if (!in_array($corp, ['rcw','wac','usc','cfr'], true)) {
        echo json_encode(['error' => 'Invalid corpus']); exit;
    }
    $secrets = load_secrets($secretsFile);
    $url = rtrim($secrets['SUPABASE_URL'] ?? '', '/') . '/rest/v1/rpc/rcw_wac_catalog';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '               . ($secrets['SUPABASE_ANON_KEY'] ?? ''),
            'Authorization: Bearer ' . ($secrets['SUPABASE_ANON_KEY'] ?? ''),
        ],
        CURLOPT_POSTFIELDS => json_encode(['filter_corpus' => $corp]),
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo ($result && $httpCode === 200) ? $result : json_encode(['error' => "HTTP $httpCode"]);
    exit;
}

// ?log_tokens=1 — PATCH token counts onto an existing query_log row
if (isset($_GET['log_tokens'])) {
    header('Content-Type: application/json');
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $logId = (int)($d['log_id'] ?? 0);
    if ($logId > 0) {
        $secrets = load_secrets($secretsFile);
        $proxy   = new RcwWacProxy($secrets);
        try { $proxy->logTokens($logId, $d); } catch (\Throwable $e) {}
    }
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['stream']) && $_GET['stream'] === 'test') {
    start_sse();
    foreach (['LEO', 'reference', 'streaming', 'OK!'] as $w) {
        sleep(1); sse(['text' => $w . ' ']);
    }
    sse(['meta' => ['inTokens' => 0, 'outTokens' => 4, 'cachedTokens' => 0]]);
    echo "data: [DONE]\n\n"; flush();
    exit;
}

if (!isset($_GET['stream']) || $_GET['stream'] !== '1') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Use ?stream=1']);
    exit;
}

// ── Main stream request ───────────────────────────────────────────────────────

start_sse();

$query     = trim($data['query'] ?? $data['messages'][array_key_last($data['messages'] ?? [])]['content'] ?? '');
$corpusRaw = $data['corpus'] ?? null;
$corpus    = in_array($corpusRaw, ['rcw', 'wac', 'usc', 'cfr', 'state', 'federal'], true) ? $corpusRaw : null;
$messages  = $data['messages'] ?? [];

if (!$query) { sse_error('No query provided.'); exit; }

$secrets = load_secrets($secretsFile);
if (empty($secrets['ANTHROPIC_API_KEY'])) { sse_error('ANTHROPIC_API_KEY missing.'); exit; }
if (empty($secrets['OPENAI_API_KEY']))    { sse_error('OPENAI_API_KEY missing.');    exit; }

$proxy = new RcwWacProxy($secrets);

try {
    $embedding = $proxy->getEmbedding($query);
} catch (EmbeddingException $e) {
    sse_error('Embedding failed: ' . $e->getMessage()); exit;
}

try {
    $results = $proxy->searchSupabase($embedding, $corpus, 8, $query);
} catch (SupabaseException $e) {
    sse_error('Search failed: ' . $e->getMessage()); exit;
}

$built   = $proxy->buildContext($results);
$context = $built['context'];
$sources = $built['sources'];

sse(['sources' => $sources]);

$logId = 0;
try { $logId = $proxy->logQuery($query, $corpus, count($results)); } catch (\Throwable $e) {}
if ($logId) { sse(['log_id' => $logId]); }

$systemText = str_replace('{CONTEXT}',
    $context ?: '(No matching sections found — answer from general knowledge if possible, but note the gap.)',
    SYSTEM_PROMPT
);

$claudeMessages = [];
$contextInjected = false;

foreach ($messages as $msg) {
    $role = $msg['role'] ?? 'user';
    $text = '';
    if (is_string($msg['content'] ?? null)) {
        $text = $msg['content'];
    } elseif (is_array($msg['content'] ?? null)) {
        foreach ($msg['content'] as $block) { $text .= $block['text'] ?? ''; }
    }
    if ($role === 'user' && !$contextInjected) {
        $claudeMessages[] = ['role' => 'user', 'content' => $text];
        $contextInjected  = true;
    } else {
        $claudeMessages[] = ['role' => ($role === 'model' ? 'assistant' : $role), 'content' => $text];
    }
}

if (empty($claudeMessages)) {
    $claudeMessages[] = ['role' => 'user', 'content' => $query];
}

$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => MAX_OUTPUT_TOKENS,
    'stream'     => true,
    'system'     => [['type' => 'text', 'text' => $systemText]],
    'messages'   => $claudeMessages,
], JSON_UNESCAPED_UNICODE);

// ── Stream Claude ─────────────────────────────────────────────────────────────

$st = ['buf' => '', 'inTok' => 0, 'outTok' => 0, 'cacheRead' => 0, 'cacheWrite' => 0,
       'stopReason' => '', 'httpCode' => 0, 'errBody' => ''];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER,     [
    'Content-Type: application/json',
    'x-api-key: '         . $secrets['ANTHROPIC_API_KEY'],
    'anthropic-version: 2023-06-01',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_TIMEOUT,        90);

curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (...$_) {
    static $last = 0;
    $now = microtime(true);
    if ($now - $last > 4.0) { echo ": hb\n\n"; @ob_flush(); flush(); $last = $now; }
    return 0;
});

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$st) {
    if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) { $st['httpCode'] = (int)$m[1]; }
    return strlen($header);
});

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$st) {
    if ($st['httpCode'] !== 0 && $st['httpCode'] !== 200) {
        $st['errBody'] .= $chunk; return strlen($chunk);
    }
    $st['buf'] .= $chunk;
    while (($nl = strpos($st['buf'], "\n")) !== false) {
        $line = rtrim(substr($st['buf'], 0, $nl), "\r");
        $st['buf'] = substr($st['buf'], $nl + 1);
        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $event = json_decode(substr($line, 6), true);
        if ($event === null) continue;
        switch ($event['type'] ?? '') {
            case 'message_start':
                $u = $event['message']['usage'] ?? [];
                $st['inTok']     = (int)($u['input_tokens']                ?? 0);
                $st['cacheRead'] = (int)($u['cache_read_input_tokens']     ?? 0);
                $st['cacheWrite']= (int)($u['cache_creation_input_tokens'] ?? 0);
                break;
            case 'content_block_delta':
                if (($event['delta']['type'] ?? '') === 'text_delta') {
                    $txt = $event['delta']['text'] ?? '';
                    if ($txt !== '') { sse(['text' => $txt]); }
                }
                break;
            case 'message_delta':
                $st['outTok']     = (int)($event['usage']['output_tokens'] ?? 0);
                $st['stopReason'] = $event['delta']['stop_reason'] ?? '';
                break;
            case 'error':
                sse(['error' => $event['error']['message'] ?? 'Claude error']);
                break;
        }
    }
    return strlen($chunk);
});

curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlErrno) {
    sse(['error' => "curl error $curlErrno: $curlError"]);
} elseif ($st['httpCode'] !== 200) {
    $body = json_decode($st['errBody'], true);
    sse(['error' => $body['error']['message'] ?? "Anthropic returned HTTP {$st['httpCode']}"]);
}

sse(['meta' => [
    'inTokens'     => $st['inTok'],
    'outTokens'    => $st['outTok'],
    'cachedTokens' => $st['cacheRead'],
    'cacheWrite'   => $st['cacheWrite'],
    'resultCount'  => count($results),
    'stopReason'   => $st['stopReason'],
]]);
echo "data: [DONE]\n\n";
flush();

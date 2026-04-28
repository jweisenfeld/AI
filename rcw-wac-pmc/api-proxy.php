<?php
/**
 * PMC Legal Reference — Streaming Proxy
 *
 * Derivative of rcw-wac/api-proxy.php. System prompt tuned for Pasco Municipal Code analysis.
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
You are a legal reference assistant focused on the Pasco Municipal Code (PMC), with optional cross-checking against Washington and federal law when retrieved context includes those corpora.

Primary corpus:
- PMC — Pasco Municipal Code (city ordinances)

Optional companion corpora (if selected/retrieved):
- RCW — Revised Code of Washington (state statutes)
- WAC — Washington Administrative Code (state agency rules)
- USC — United States Code (federal statutes)
- CFR — Code of Federal Regulations (federal rules)

When answering:
- Treat PMC as primary authority unless the user selected another source scope.
- Reconcile the user question against all retrieved PMC sections and cite each relevant section (e.g., “PMC 25.12.040”).
- Return all potentially binding code provisions that appear relevant, including definitions, exceptions, enforcement, penalties, and procedural sections.
- Explicitly highlight conflicts, ambiguities, or synergies among retrieved provisions.
- If state/federal sources are retrieved, clearly distinguish municipal code from state/federal law and explain interaction/preemption risks at a high level.
- If the query is in Spanish or Russian, answer in the same language unless the user asks otherwise.
- Use plain language and structured bullet points for practitioners.
- If retrieved context is incomplete, say what is missing and what additional sections should be checked.
- Do not invent or speculate about sections that are not in the retrieved context.

IMPORTANT: This tool provides general legal information only — not legal advice. For specific legal decisions, users should consult the City Attorney or qualified counsel.

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
    if (!in_array($corp, ['pmc','rcw','wac','usc','cfr'], true)) {
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
    foreach (['PMC', 'reference', 'streaming', 'OK!'] as $w) {
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
$corpus    = in_array($corpusRaw, ['pmc', 'rcw', 'wac', 'usc', 'cfr', 'state', 'federal', 'local'], true) ? $corpusRaw : null;
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
       'stopReason' => '', 'httpCode' => 0, 'errBody' => '',
       'textChars' => 0, 'sawErrorEvent' => false];

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
            case 'content_block_start':
                if (($event['content_block']['type'] ?? '') === 'text') {
                    $txt = $event['content_block']['text'] ?? '';
                    if ($txt !== '') {
                        $st['textChars'] += strlen($txt);
                        sse(['text' => $txt]);
                    }
                }
                break;
            case 'content_block_delta':
                if (($event['delta']['type'] ?? '') === 'text_delta') {
                    $txt = $event['delta']['text'] ?? '';
                    if ($txt !== '') {
                        $st['textChars'] += strlen($txt);
                        sse(['text' => $txt]);
                    }
                }
                break;
            case 'message_delta':
                $st['outTok']     = (int)($event['usage']['output_tokens'] ?? 0);
                $st['stopReason'] = $event['delta']['stop_reason'] ?? '';
                break;
            case 'error':
                $st['sawErrorEvent'] = true;
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
} elseif ($st['textChars'] === 0 && !$st['sawErrorEvent']) {
    $reason = $st['stopReason'] ?: 'unknown';
    $fallback = "I couldn't generate a full narrative answer for this request "
        . "(stop_reason={$reason}).\n\n";
    if (!empty($sources)) {
        $fallback .= "Most relevant retrieved sections:\n";
        foreach (array_slice($sources, 0, 6) as $s) {
            $sid = $s['section_id'] ?? 'Unknown section';
            $hd  = trim((string)($s['section_heading'] ?? ''));
            $fallback .= "- {$sid}" . ($hd !== '' ? " — {$hd}" : '') . "\n";
        }
        $fallback .= "\nTry narrowing the question (for example: title/chapter, permit type, or one scenario), "
                  . "or run again with a different Source scope.";
    } else {
        $fallback .= "No source sections were retrieved. Try broadening your query or switching Source scope.";
    }
    sse(['text' => $fallback]);
    sse(['error' => "Model returned no text content (stop_reason={$reason})."]);
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

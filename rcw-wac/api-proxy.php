<?php
/**
 * RCW/WAC Legal RAG — Streaming Proxy
 *
 * Request:  POST api-proxy.php?stream=1
 * Body:     { "query": "...", "corpus": "rcw|wac|both", "messages": [...] }
 *
 * SSE event sequence:
 *   data: {"sources": [...]}        ← retrieved law sections (before Claude starts)
 *   data: {"text": "..."}           ← streamed Claude response delta
 *   data: {"meta": {...}}           ← token counts
 *   data: [DONE]
 *
 * Secrets: ../.secrets/rcwkey.php   (separate from ohs-search — new Supabase project)
 * Keys:    ANTHROPIC_API_KEY, OPENAI_API_KEY, SUPABASE_URL, SUPABASE_ANON_KEY (anon only)
 */

set_time_limit(120);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

require_once __DIR__ . '/src/RcwWacProxy.php';
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
    // Variable-style secrets file: $OPENAI_API_KEY = '...' etc.
    return [
        'OPENAI_API_KEY'    => $OPENAI_API_KEY    ?? '',
        'SUPABASE_URL'      => $SUPABASE_URL       ?? '',
        'SUPABASE_ANON_KEY' => $SUPABASE_ANON_KEY  ?? '',
        'ANTHROPIC_API_KEY' => $ANTHROPIC_API_KEY  ?? '',
    ];
}

// ── System prompt ─────────────────────────────────────────────────────────────

define('SYSTEM_PROMPT', <<<'PROMPT'
You are a legal reference assistant covering Washington State law and relevant federal law. You help people understand:

- RCW  — Revised Code of Washington (state statute, enacted by the Legislature)
- WAC  — Washington Administrative Code (state agency rules under legislative authority)
- USC  — United States Code (federal statute, enacted by Congress)
- CFR  — Code of Federal Regulations (federal agency rules under Congressional authority)

The legal hierarchy: federal law supersedes state law. For special education, IDEA (20 USC Ch. 33) and its regulations (34 CFR Part 300) set the floor; WAC 392-172A and RCW 28A.155 implement those requirements in Washington and may add to them.

When answering:
- Cite every specific section you draw from (e.g., "RCW 28A.400.010", "WAC 392-172A-03300", "34 CFR § 300.8", "20 USC § 1415").
- Distinguish the source type: state statute (RCW), state rule (WAC), federal statute (USC), federal rule (CFR).
- When federal and state sections both apply, explain how they interact — federal sets the floor, state may add requirements.
- Write in plain language; translate legal jargon for a general audience.
- If a section only partially answers the question, say what you found and note the gap.
- Do not guess or speculate about sections not provided.

IMPORTANT: This tool provides general legal information only. It is not legal advice. For specific legal situations, consult a licensed attorney.

The following law sections were retrieved as relevant to the user's question:

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

// ?stream=test — streaming sanity check (no API calls)
if (isset($_GET['stream']) && $_GET['stream'] === 'test') {
    start_sse();
    $words = ['RCW/WAC', 'streaming', 'is', 'working!'];
    foreach ($words as $w) {
        sleep(1);
        sse(['text' => $w . ' ']);
    }
    sse(['meta' => ['inTokens' => 0, 'outTokens' => count($words), 'cachedTokens' => 0]]);
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

// Validate input
$query        = trim($data['query']   ?? $data['messages'][array_key_last($data['messages'] ?? [])]['content'] ?? '');
$corpusRaw    = $data['corpus'] ?? 'both';
$corpus       = in_array($corpusRaw, ['rcw', 'wac', 'usc', 'cfr', 'state', 'federal'], true) ? $corpusRaw : null;
$messages     = $data['messages']     ?? [];

if (!$query) {
    sse_error('No query provided.'); exit;
}

// Load secrets
$secrets = load_secrets($secretsFile);
if (empty($secrets['ANTHROPIC_API_KEY'])) {
    sse_error('ANTHROPIC_API_KEY missing.'); exit;
}
if (empty($secrets['OPENAI_API_KEY'])) {
    sse_error('OPENAI_API_KEY missing.'); exit;
}

$proxy = new RcwWacProxy($secrets);

// ── Step 1 + 2: Embed + Search (synchronous, before streaming) ───────────────

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

// Emit sources immediately (before Claude starts) so the UI can render them
sse(['sources' => $sources]);

// Log query (fire-and-forget — errors silently ignored)
try { $proxy->logQuery($query, $corpus, count($results)); } catch (\Throwable $e) {}

// ── Step 3: Build Claude messages ────────────────────────────────────────────

$systemText = str_replace('{CONTEXT}',
    $context ?: '(No matching sections found — answer from general knowledge if possible, but note the gap.)',
    SYSTEM_PROMPT
);

// Build messages for Claude: inject context only on the first user turn
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
        // The system prompt already has the context; just send the user question
        $claudeMessages[] = ['role' => 'user', 'content' => $text];
        $contextInjected  = true;
    } else {
        $claudeMessages[] = ['role' => ($role === 'model' ? 'assistant' : $role), 'content' => $text];
    }
}

// Fallback: if messages array was empty, use the query directly
if (empty($claudeMessages)) {
    $claudeMessages[] = ['role' => 'user', 'content' => $query];
}

$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',   // fast + cheap for RAG synthesis
    'max_tokens' => MAX_OUTPUT_TOKENS,
    'stream'     => true,
    'system'     => [['type' => 'text', 'text' => $systemText]],
    'messages'   => $claudeMessages,
], JSON_UNESCAPED_UNICODE);

// ── Step 4: Stream Claude ─────────────────────────────────────────────────────

$st = [
    'buf'        => '',
    'inTok'      => 0,
    'outTok'     => 0,
    'cacheRead'  => 0,
    'cacheWrite' => 0,
    'stopReason' => '',
    'httpCode'  => 0,
    'errBody'   => '',
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_POST,       true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: '         . $secrets['ANTHROPIC_API_KEY'],
    'anthropic-version: 2023-06-01',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);   // must be false for write callback
curl_setopt($ch, CURLOPT_TIMEOUT,        90);

// Heartbeat to prevent LiteSpeed idle timeout during slow TTFB
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (...$_) {
    static $last = 0;
    $now = microtime(true);
    if ($now - $last > 4.0) {
        echo ": hb\n\n";
        @ob_flush(); flush();
        $last = $now;
    }
    return 0;
});

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$st) {
    if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) {
        $st['httpCode'] = (int)$m[1];
    }
    return strlen($header);
});

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$st) {
    if ($st['httpCode'] !== 0 && $st['httpCode'] !== 200) {
        $st['errBody'] .= $chunk;
        return strlen($chunk);
    }

    $st['buf'] .= $chunk;
    while (($nl = strpos($st['buf'], "\n")) !== false) {
        $line      = rtrim(substr($st['buf'], 0, $nl), "\r");
        $st['buf'] = substr($st['buf'], $nl + 1);

        if (strncmp($line, 'data: ', 6) !== 0) continue;
        $event = json_decode(substr($line, 6), true);
        if ($event === null) continue;

        switch ($event['type'] ?? '') {
            case 'message_start':
                $u = $event['message']['usage'] ?? [];
                $st['inTok']     = (int)($u['input_tokens']               ?? 0);
                $st['cacheRead'] = (int)($u['cache_read_input_tokens']    ?? 0);
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

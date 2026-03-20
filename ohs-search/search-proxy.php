<?php
/**
 * OHS Memory — Search Proxy
 * Receives a query, embeds it with OpenAI, searches Supabase,
 * and optionally synthesizes an answer with Claude.
 *
 * Flow (synthesis mode):
 *   Browser → POST here → OpenAI embeddings → Supabase RPC → Claude synthesis → JSON response → Browser
 *
 * Pass synthesize: true in the request body to enable the answer generation step.
 * index.html sends synthesize: true.  index0.html does not (raw results only).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Secrets ────────────────────────────────────────────────────────────────────
$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}
$secrets = require $secretsFile;

$OPENAI_API_KEY    = $secrets['OPENAI_API_KEY']    ?? null;
$SUPABASE_URL      = $secrets['SUPABASE_URL']      ?? null;
$SUPABASE_ANON_KEY = $secrets['SUPABASE_ANON_KEY'] ?? null;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;
$EMBEDDING_MODEL   = 'text-embedding-3-small';
$SYNTHESIS_MODEL   = 'claude-haiku-4-5';

if (!$OPENAI_API_KEY || !$SUPABASE_URL || !$SUPABASE_ANON_KEY) {
    http_response_code(500);
    error_log("Missing required keys in $secretsFile");
    echo json_encode(['error' => 'Server configuration error (key missing).']);
    exit;
}

// ── Input ──────────────────────────────────────────────────────────────────────

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Also accept form POST for simplicity
    $input = $_POST;
}

$query      = trim($input['query']      ?? '');
$subject    = trim($input['subject']    ?? '') ?: null;
$year       = trim($input['year']       ?? '') ?: null;
$doc_type   = trim($input['doc_type']   ?? '') ?: null;
$chunk_size = trim($input['chunk_size'] ?? '') ?: null;
$limit      = min((int)($input['limit'] ?? 8), 20);
$synthesize = !empty($input['synthesize']);

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'Query is required']);
    exit;
}

// ── Step 1: Embed the query with OpenAI ───────────────────────────────────────

function get_embedding(string $text, string $apiKey, string $model): array {
    $payload = json_encode([
        'input' => $text,
        'model' => $model,
    ]);

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("OpenAI embeddings failed (HTTP $http_code): $response");
    }

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'];
}

// ── Custom exception for Supabase statement timeouts ─────────────────────────
class SupabaseTimeoutException extends RuntimeException {}

// ── Step 2: Search Supabase via RPC ───────────────────────────────────────────

function search_supabase(array $embedding, ?string $subject, ?string $year,
                          ?string $doc_type, ?string $chunk_size, int $limit,
                          string $supabaseUrl, string $anonKey): array {
    $params = [
        'query_embedding' => $embedding,
        'match_count'     => $limit,
    ];
    if ($subject)    $params['filter_subject']    = $subject;
    if ($year)       $params['filter_year']       = $year;
    if ($doc_type)   $params['filter_doc_type']   = $doc_type;
    if ($chunk_size && in_array($chunk_size, ['small', 'large'])) {
        $params['filter_chunk_size'] = $chunk_size;
    }

    $ch = curl_init($supabaseUrl . '/rest/v1/rpc/search_ohs_memory');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '         . $anonKey,
            'Authorization: Bearer ' . $anonKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($params),
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        throw new RuntimeException("Supabase connection error: $curl_err");
    }

    if ($http_code !== 200) {
        $errBody = json_decode($response, true);
        $pgCode  = isset($errBody['code'])    ? (string)$errBody['code'] : '';
        $pgMsg   = isset($errBody['message']) ? $errBody['message']      : $response;
        if ($pgCode === '57014') {
            throw new SupabaseTimeoutException(
                "The database search timed out (limit=$limit). $pgMsg"
            );
        }
        throw new RuntimeException("Supabase search failed (HTTP $http_code): $pgMsg");
    }

    return json_decode($response, true) ?? [];
}

// ── Step 3: Synthesize answer with Claude ─────────────────────────────────────

function synthesize_answer(string $query, array $results, string $anthropicKey,
                            string $model): string {
    if (empty($results)) {
        return "No relevant documents were found in the OHS knowledge base for this query. "
             . "This may mean the topic hasn't been ingested yet, or try rephrasing.";
    }

    // Build context block from top results
    $context = '';
    foreach (array_slice($results, 0, 6) as $i => $r) {
        $n      = $i + 1;
        $source = $r['original_filename'] ?? $r['source'] ?? 'Unknown';
        $year   = $r['school_year'] ?? '';
        $type   = isset($r['doc_type']) ? str_replace('_', ' ', $r['doc_type']) : '';
        $meta   = implode(', ', array_filter([$source, $year, $type]));
        $context .= "[Source $n: $meta]\n" . ($r['content'] ?? '') . "\n\n";
    }

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 600,
        'system'     => 'You are a helpful assistant for Orion High School staff in Pasco, Washington. '
                      . 'You answer questions about school operations, policies, staff, students, and '
                      . 'institutional history based on archived documents. '
                      . 'Be direct, warm, and useful. Write in plain prose — no bullet lists unless the '
                      . 'question specifically calls for a list. '
                      . 'When you draw on a specific source, cite it inline as [Source N]. '
                      . 'If the documents only partially answer the question, say what you know and '
                      . 'acknowledge the gap.',
        'messages'   => [[
            'role'    => 'user',
            'content' => "Question: $query\n\nDocuments:\n$context\nAnswer based on the documents above.",
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Claude synthesis failed (HTTP $http_code): $response");
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? 'Unable to generate synthesis.';
}

// ── Execute ────────────────────────────────────────────────────────────────────

try {
    $embedding = get_embedding($query, $OPENAI_API_KEY, $EMBEDDING_MODEL);

    $warning = null;
    try {
        $results = search_supabase($embedding, $subject, $year, $doc_type, $chunk_size, $limit,
                                   $SUPABASE_URL, $SUPABASE_ANON_KEY);
    } catch (SupabaseTimeoutException $e) {
        // Retry once with a smaller result set
        $retryLimit = 4;
        error_log("OHS search timeout (limit=$limit); retrying with limit=$retryLimit. " . $e->getMessage());
        $results = search_supabase($embedding, $subject, $year, $doc_type, $chunk_size, $retryLimit,
                                   $SUPABASE_URL, $SUPABASE_ANON_KEY);
        $warning = "Search took longer than expected — showing top $retryLimit results instead of $limit. "
                 . "Try a more specific query if you need more.";
    }

    $response = [
        'query'   => $query,
        'count'   => count($results),
        'results' => $results,
    ];

    if ($warning) {
        $response['warning'] = $warning;
    }

    if ($synthesize) {
        if (!$ANTHROPIC_API_KEY) {
            $response['answer'] = null;
            $response['answer_error'] = 'ANTHROPIC_API_KEY not configured in .secrets/ohskey.php';
        } else {
            $response['answer'] = synthesize_answer($query, $results, $ANTHROPIC_API_KEY,
                                                     $SYNTHESIS_MODEL);
        }
    }

    echo json_encode($response);

} catch (SupabaseTimeoutException $e) {
    // Timeout even on the retry — give a clear, actionable message
    http_response_code(500);
    error_log("OHS search timeout on retry: " . $e->getMessage());
    echo json_encode([
        'error' => "The database search timed out. This usually means the knowledge base is under "
                 . "heavy load or the query is very broad. Please wait a moment and try again, "
                 . "or use the subject/year filters to narrow your search.",
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("OHS search error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}

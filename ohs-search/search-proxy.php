<?php
/**
 * OHS Memory — Search Proxy
 * Receives a query from index.html, embeds it with OpenAI,
 * calls the Supabase search_ohs_memory() function, returns JSON.
 *
 * Flow:
 *   Browser → POST here → OpenAI embeddings → Supabase RPC → JSON response → Browser
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

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

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'Query is required']);
    exit;
}

// ── Step 1: Embed the query with OpenAI ───────────────────────────────────────

function get_embedding(string $text): array {
    $payload = json_encode([
        'input' => $text,
        'model' => EMBEDDING_MODEL,
    ]);

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
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

// ── Step 2: Search Supabase via RPC ───────────────────────────────────────────

function search_supabase(array $embedding, ?string $subject, ?string $year,
                          ?string $doc_type, ?string $chunk_size, int $limit): array {
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

    $ch = curl_init(SUPABASE_URL . '/rest/v1/rpc/search_ohs_memory');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '         . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($params),
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Supabase search failed (HTTP $http_code): $response");
    }

    return json_decode($response, true) ?? [];
}

// ── Execute ────────────────────────────────────────────────────────────────────

try {
    $embedding = get_embedding($query);
    $results   = search_supabase($embedding, $subject, $year, $doc_type, $chunk_size, $limit);

    echo json_encode([
        'query'   => $query,
        'count'   => count($results),
        'results' => $results,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

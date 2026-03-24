<?php
/**
 * OHS Memory — Search Proxy  (entry point)
 *
 * Thin HTTP wrapper around SearchProxy.  All business logic lives in
 * src/SearchProxy.php so it can be unit-tested without a live network.
 *
 * Flow (synthesis mode):
 *   Browser → POST here → OpenAI embeddings → Supabase RPC → Claude synthesis → JSON response
 *
 * Pass synthesize: true in the request body to enable answer generation.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/src/SearchProxy.php';

use OHSSearch\SearchProxy;
use OHSSearch\SupabaseTimeoutException;

// ── Secrets ────────────────────────────────────────────────────────────────────

$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}
$secrets = require $secretsFile;

foreach (['OPENAI_API_KEY', 'SUPABASE_URL', 'SUPABASE_ANON_KEY'] as $key) {
    if (empty($secrets[$key])) {
        http_response_code(500);
        error_log("Missing key '$key' in $secretsFile");
        echo json_encode(['error' => "Server configuration error ($key missing)."]);
        exit;
    }
}

// ── Input ──────────────────────────────────────────────────────────────────────

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$query          = trim($input['query']      ?? '');
$subject        = trim($input['subject']    ?? '') ?: null;
$year           = trim($input['year']       ?? '') ?: null;
$doc_type       = trim($input['doc_type']   ?? '') ?: null;
$chunk_size     = trim($input['chunk_size'] ?? '') ?: null;
$limit          = min((int)($input['limit'] ?? 8), 20);
$min_similarity = isset($input['min_similarity']) ? (float)$input['min_similarity'] : 0.25;
$synthesize     = !empty($input['synthesize']);

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'Query is required']);
    exit;
}

// ── Execute ────────────────────────────────────────────────────────────────────

$proxy = new SearchProxy($secrets);

try {
    $embedding = $proxy->getEmbedding($query);

    // Search with automatic retry on timeout
    $warning = null;
    try {
        $results = $proxy->searchSupabase($embedding, $subject, $year, $doc_type, $chunk_size, $limit, $min_similarity);
    } catch (SupabaseTimeoutException $e) {
        $retryLimit = 4;
        error_log("OHS search timeout (limit=$limit); retrying with limit=$retryLimit. " . $e->getMessage());
        $results = $proxy->searchSupabase($embedding, $subject, $year, $doc_type, $chunk_size, $retryLimit, $min_similarity);
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
        if (empty($secrets['ANTHROPIC_API_KEY'])) {
            $response['answer']       = null;
            $response['answer_error'] = 'ANTHROPIC_API_KEY not configured in .secrets/ohskey.php';
        } else {
            $response['answer'] = $proxy->synthesizeAnswer($query, $results);
        }
    }

    $proxy->logQuery($query, count($results), $synthesize && isset($response['answer']));

    echo json_encode($response);

} catch (SupabaseTimeoutException $e) {
    http_response_code(500);
    error_log("OHS search timeout on retry: " . $e->getMessage());
    echo json_encode([
        'error' => "The search timed out even after retrying. "
                 . "Try using the subject or year filters to narrow the query.",
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("OHS search error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}

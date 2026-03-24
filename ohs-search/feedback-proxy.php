<?php
/**
 * OHS Memory — Feedback Proxy
 *
 * Receives a thumbs-up or thumbs-down vote on a synthesized answer and
 * stores it in Supabase via the insert_feedback() RPC.
 *
 * POST body (JSON):
 *   query        string  required  — verbatim query text
 *   vote         string  required  — 'up' or 'down'
 *   answer       string  optional  — full answer text that was voted on
 *   result_count int     optional  — number of source chunks returned
 *   reporter     string  optional  — teacher name
 *   comment      string  optional  — free-form note
 *
 * Returns: { ok: true, id: N } or { error: "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Secrets ────────────────────────────────────────────────────────────────────

$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
if (!is_readable($secretsFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}
$secrets = require $secretsFile;

foreach (['SUPABASE_URL', 'SUPABASE_ANON_KEY'] as $key) {
    if (empty($secrets[$key])) {
        http_response_code(500);
        echo json_encode(['error' => "Server configuration error ($key missing)."]);
        exit;
    }
}

// ── Input ──────────────────────────────────────────────────────────────────────

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$query        = trim($input['query']        ?? '');
$vote         = trim($input['vote']         ?? '');
$answer       = trim($input['answer']       ?? '') ?: null;
$result_count = (int)($input['result_count'] ?? 0);
$reporter     = trim($input['reporter']     ?? '') ?: null;
$comment      = trim($input['comment']      ?? '') ?: null;

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['error' => 'query is required.']);
    exit;
}
if (!in_array($vote, ['up', 'down'])) {
    http_response_code(400);
    echo json_encode(['error' => "vote must be 'up' or 'down'."]);
    exit;
}

// ── Call Supabase RPC ──────────────────────────────────────────────────────────

$url     = rtrim($secrets['SUPABASE_URL'], '/') . '/rest/v1/rpc/insert_feedback';
$payload = json_encode([
    'p_query_text'   => $query,
    'p_answer_text'  => $answer,
    'p_vote'         => $vote,
    'p_result_count' => $result_count,
    'p_reporter'     => $reporter,
    'p_comment'      => $comment,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'apikey: '         . $secrets['SUPABASE_ANON_KEY'],
        'Authorization: Bearer ' . $secrets['SUPABASE_ANON_KEY'],
    ],
]);

$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status >= 200 && $status < 300) {
    $data = json_decode($resp, true);
    echo json_encode(['ok' => true, 'id' => $data['id'] ?? null]);
} else {
    http_response_code(500);
    error_log("OHS feedback insert failed (HTTP $status): $resp");
    echo json_encode(['error' => 'Failed to save feedback.']);
}

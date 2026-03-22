<?php
/**
 * FlightLog — Email Ingestion Proxy
 *
 * Accepts a single email from the Outlook VBA macro (IngestSelectedEmail.bas),
 * chunks the text, embeds it via OpenAI, and stores everything in Supabase.
 * Calls the insert_email_document() Postgres function so vector casting happens
 * inside the database (PostgREST cannot cast text → vector automatically).
 *
 * Authentication: shared INGEST_KEY in the request body.  The key lives in
 * .secrets/ohskey.php on the server; the same key is compiled into the VBA macro.
 * This is write-only access — the endpoint cannot read any document content.
 *
 * Required additions to .secrets/ohskey.php:
 *   'SUPABASE_SERVICE_KEY' => 'eyJ...',   // service role key (allows writes)
 *   'INGEST_KEY'           => '...',       // long random shared secret
 *
 * Request body (JSON, POST):
 *   key          — shared INGEST_KEY
 *   subject      — email subject line
 *   sender       — full From: header  (e.g. "John Weisenfeld <john@psd1.net>")
 *   sender_name  — display name only  (e.g. "John Weisenfeld")
 *   to           — To: header
 *   date         — sent date as "YYYY-MM-DD HH:MM:SS"
 *   body         — plain-text email body (truncated to 50 000 chars by VBA)
 *
 * Response (JSON):
 *   { ok: true,  doc_id: "uuid", chunks: N, year: "2025-26", teacher: "..." }
 *   { ok: false, skipped: true,  doc_id: "uuid", message: "Already in FlightLog" }
 *   { error: "..." }   on validation or API failure
 */

header('Content-Type: application/json');

// ── Secrets ─────────────────────────────────────────────────────────────────

$secretsFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/.secrets/ohskey.php';
if (!is_readable($secretsFile)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Server configuration error (secrets missing).']));
}
$secrets = require $secretsFile;

foreach (['OPENAI_API_KEY', 'SUPABASE_URL', 'SUPABASE_SERVICE_KEY', 'INGEST_KEY'] as $k) {
    if (empty($secrets[$k])) {
        http_response_code(500);
        exit(json_encode(['error' => "Server configuration error ($k missing)."]));
    }
}

// ── Input validation ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST required']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON body']));
}

// Authenticate
if (($input['key'] ?? '') !== $secrets['INGEST_KEY']) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$subject     = trim($input['subject']     ?? '') ?: '(no subject)';
$sender      = trim($input['sender']      ?? '');
$sender_name = trim($input['sender_name'] ?? '') ?: $sender;
$to_field    = trim($input['to']          ?? '');
$date_str    = trim($input['date']        ?? '');
$email_body  = trim($input['body']        ?? '');

if (!$email_body) {
    http_response_code(400);
    exit(json_encode(['error' => 'Email body is empty — nothing to ingest']));
}

// ── Build full text (mirrors _extract_text_from_msg in ingest.py) ────────────

$lines = [];
if ($subject)   $lines[] = "Subject: $subject";
if ($sender)    $lines[] = "From: $sender";
if ($to_field)  $lines[] = "To: $to_field";
if ($date_str)  $lines[] = "Date: $date_str";
$lines[] = '';
$lines[] = $email_body;
$full_text = implode("\n", $lines);

// ── Dedup hash ───────────────────────────────────────────────────────────────

$source_hash = hash('sha256', $full_text);

// ── Detect school year ───────────────────────────────────────────────────────

$school_year = detect_school_year($date_str);

// ── Chunk text at two sizes (word-aware, mirrors ingest.py token targets) ────
//
// Target sizes in tokens; 1 token ≈ 0.75 English words.
//   small:  200 tokens → ~150 words, 40-token overlap → ~30 words
//   large:  900 tokens → ~675 words, 120-token overlap → ~90 words

$small_chunks = chunk_words($full_text, 150, 30);
$large_chunks = chunk_words($full_text, 675, 90);

// Annotate with size label and position index
$all_chunks = [];
foreach ($small_chunks as $i => $text) {
    $all_chunks[] = ['size' => 'small', 'text' => $text, 'position' => $i];
}
foreach ($large_chunks as $i => $text) {
    $all_chunks[] = ['size' => 'large', 'text' => $text, 'position' => $i];
}

if (!$all_chunks) {
    http_response_code(400);
    exit(json_encode(['error' => 'Email produced no text chunks after processing']));
}

// ── Embed all chunks via OpenAI (one batch call) ─────────────────────────────

$texts = array_column($all_chunks, 'text');

try {
    $embeddings = openai_embed($texts, $secrets['OPENAI_API_KEY']);
} catch (Exception $e) {
    http_response_code(502);
    exit(json_encode(['error' => 'OpenAI embedding failed: ' . $e->getMessage()]));
}

// Attach embeddings and approximate token counts to chunk objects
for ($i = 0; $i < count($all_chunks); $i++) {
    $all_chunks[$i]['embedding'] = format_vector($embeddings[$i]);
    $all_chunks[$i]['tokens']    = (int) round(str_word_count($all_chunks[$i]['text']) / 0.75);
}

// ── Insert document + chunks via Supabase RPC ─────────────────────────────────

$rpc_params = [
    'p_source_hash'  => $source_hash,
    'p_source_file'  => 'outlook:' . $sender . ':' . $date_str,
    'p_filename'     => $subject . '.msg',
    'p_school_year'  => $school_year,
    'p_teacher'      => $sender_name ?: null,
    'p_unit'         => 'Orion Planning Team',
    'p_notes'        => 'Ingested via Outlook macro' . ($sender_name ? " by $sender_name" : ''),
    'p_raw_text'     => $full_text,
    'p_chunks'       => $all_chunks,
];

try {
    $result = supabase_rpc(
        'insert_email_document',
        $rpc_params,
        $secrets['SUPABASE_URL'],
        $secrets['SUPABASE_SERVICE_KEY']
    );
} catch (Exception $e) {
    http_response_code(502);
    exit(json_encode(['error' => 'Database insert failed: ' . $e->getMessage()]));
}

// $result is the JSONB returned by insert_email_document()
if (!empty($result['skipped'])) {
    exit(json_encode([
        'ok'      => false,
        'skipped' => true,
        'doc_id'  => $result['doc_id'],
        'message' => 'Already in FlightLog',
    ]));
}

exit(json_encode([
    'ok'      => true,
    'doc_id'  => $result['doc_id'],
    'chunks'  => $result['chunks'],
    'year'    => $school_year,
    'teacher' => $sender_name,
]));


// ── Helper functions ──────────────────────────────────────────────────────────

/**
 * Split text into overlapping word-count chunks.
 *
 * @param string $text           Full text to chunk
 * @param int    $chunk_words    Target words per chunk
 * @param int    $overlap_words  Words to repeat between adjacent chunks
 * @return string[]
 */
function chunk_words(string $text, int $chunk_words, int $overlap_words): array
{
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $total = count($words);
    if ($total === 0) return [];

    $chunks = [];
    $start  = 0;
    while ($start < $total) {
        $end      = min($start + $chunk_words, $total);
        $chunks[] = implode(' ', array_slice($words, $start, $end - $start));
        if ($end >= $total) break;
        $start   += max($chunk_words - $overlap_words, 1);
    }
    return $chunks;
}

/**
 * Convert a float[] embedding to the pgvector text format "[x,y,...,z]".
 * This string is passed to Postgres and cast with ::vector inside the SQL function.
 */
function format_vector(array $embedding): string
{
    return '[' . implode(',', array_map(fn($f) => sprintf('%.8f', $f), $embedding)) . ']';
}

/**
 * Embed an array of texts via OpenAI text-embedding-3-small.
 * Returns an array of float[] embeddings in the same order as input.
 *
 * @param string[] $texts
 * @return float[][]
 * @throws Exception on HTTP or API error
 */
function openai_embed(array $texts, string $api_key): array
{
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model' => 'text-embedding-3-small',
            'input' => $texts,
        ]),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new Exception("HTTP $code: $body");
    }

    $data = json_decode($body, true);
    if (empty($data['data'])) {
        throw new Exception("Unexpected OpenAI response: $body");
    }

    // Sort by index — OpenAI guarantees order but let's be safe
    usort($data['data'], fn($a, $b) => $a['index'] <=> $b['index']);
    return array_column($data['data'], 'embedding');
}

/**
 * Call a Supabase RPC function and return the decoded JSON result.
 *
 * @throws Exception on HTTP error
 */
function supabase_rpc(string $fn, array $params, string $base_url, string $key): array
{
    $ch = curl_init("$base_url/rest/v1/rpc/$fn");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'apikey: ' . $key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_TIMEOUT        => 120,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("Supabase HTTP $code: $body");
    }

    return json_decode($body, true) ?? [];
}

/**
 * Map a date string to the OHS school-year tag.
 * Mirrors detect_school_year() in ingest.py exactly.
 */
function detect_school_year(string $date_str): ?string
{
    if (!$date_str) return null;

    $ts = strtotime($date_str);
    if ($ts === false) return null;

    $ohs_open = strtotime('2024-09-01');
    if ($ts < $ohs_open) return 'pre-opening';

    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);

    if (($y === 2024 && $m >= 9) || ($y === 2025 && $m <= 6)) return '2024-25';
    if (($y === 2025 && $m >= 7) || $y >= 2026)               return '2025-26';

    // Generic fallback for future years
    return $m >= 7 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
}

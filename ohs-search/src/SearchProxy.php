<?php
/**
 * OHS Memory — SearchProxy
 *
 * Encapsulates all three external API calls so they can be unit-tested
 * without a live network.  The entry-point script (search-proxy.php) stays
 * thin: read secrets → build SearchProxy → handle HTTP I/O.
 *
 * Curl function calls are un-prefixed so PHP's namespace resolution allows
 * tests to inject namespace-local stubs (see tests/SearchProxyTest.php).
 */

namespace OHSSearch;

// ── Exceptions ────────────────────────────────────────────────────────────────

/** Thrown when Supabase returns PostgreSQL error 57014 (statement_timeout). */
class SupabaseTimeoutException extends \RuntimeException {}

/** Thrown when Supabase returns any other non-200 response. */
class SupabaseException extends \RuntimeException {
    public function __construct(string $message, public readonly int $httpCode = 0) {
        parent::__construct($message);
    }
}

/** Thrown when the OpenAI embeddings call fails. */
class EmbeddingException extends \RuntimeException {}

/** Thrown when the Claude synthesis call fails. */
class SynthesisException extends \RuntimeException {}

// ── SearchProxy ───────────────────────────────────────────────────────────────

class SearchProxy
{
    private string $openaiKey;
    private string $supabaseUrl;
    private string $supabaseAnonKey;
    private ?string $anthropicKey;
    private string $embeddingModel;
    private string $synthesisModel;

    public function __construct(array $config)
    {
        $this->openaiKey        = $config['OPENAI_API_KEY']    ?? '';
        $this->supabaseUrl      = rtrim($config['SUPABASE_URL'] ?? '', '/');
        $this->supabaseAnonKey  = $config['SUPABASE_ANON_KEY'] ?? '';
        $this->anthropicKey     = $config['ANTHROPIC_API_KEY'] ?? null;
        $this->embeddingModel   = $config['EMBEDDING_MODEL']   ?? 'text-embedding-3-small';
        $this->synthesisModel   = $config['SYNTHESIS_MODEL']   ?? 'claude-haiku-4-5';
    }

    // ── Step 1: Embed ─────────────────────────────────────────────────────────

    /**
     * Calls OpenAI /v1/embeddings and returns the 1536-dimensional vector.
     *
     * @return float[]
     * @throws EmbeddingException on any non-200 response or missing data
     */
    public function getEmbedding(string $text): array
    {
        $payload = json_encode(['input' => $text, 'model' => $this->embeddingModel]);

        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new EmbeddingException("OpenAI connection error: $curlErr");
        }
        if ($httpCode !== 200) {
            throw new EmbeddingException("OpenAI embeddings failed (HTTP $httpCode): $response");
        }

        $data = json_decode($response, true);
        $embedding = $data['data'][0]['embedding'] ?? null;
        if (!is_array($embedding) || empty($embedding)) {
            throw new EmbeddingException("OpenAI returned no embedding data: $response");
        }
        return $embedding;
    }

    // ── Step 2: Search Supabase ───────────────────────────────────────────────

    /**
     * Calls the search_ohs_memory RPC function via Supabase REST.
     *
     * @param  float[]      $embedding  1536-dim query vector
     * @param  int          $limit      max rows to return (1–20)
     * @return array<int,array<string,mixed>>
     * @throws SupabaseTimeoutException on PG error 57014
     * @throws SupabaseException        on any other non-200 response
     * @throws \RuntimeException        on curl transport error
     */
    public function searchSupabase(
        array   $embedding,
        ?string $subject,
        ?string $year,
        ?string $docType,
        ?string $chunkSize,
        int     $limit,
        float   $minSimilarity = 0.40
    ): array {
        $params = [
            'query_embedding' => $embedding,
            'match_count'     => max(1, min($limit, 20)),
            'min_similarity'  => $minSimilarity,
        ];
        if ($subject)   $params['filter_subject']    = $subject;
        if ($year)      $params['filter_year']       = $year;
        if ($docType)   $params['filter_doc_type']   = $docType;
        if ($chunkSize && in_array($chunkSize, ['small', 'large'], true)) {
            $params['filter_chunk_size'] = $chunkSize;
        }

        $ch = curl_init($this->supabaseUrl . '/rest/v1/rpc/search_ohs_memory');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: '         . $this->supabaseAnonKey,
                'Authorization: Bearer ' . $this->supabaseAnonKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($params),
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("Supabase connection error: $curlErr");
        }

        if ($httpCode !== 200) {
            $errBody = json_decode($response, true);
            $pgCode  = isset($errBody['code']) ? (string)$errBody['code'] : '';
            $pgMsg   = $errBody['message'] ?? $response;

            if ($pgCode === '57014') {
                throw new SupabaseTimeoutException(
                    "Statement timeout (limit=$limit). $pgMsg"
                );
            }
            throw new SupabaseException(
                "Supabase search failed (HTTP $httpCode): $pgMsg",
                $httpCode
            );
        }

        return json_decode($response, true) ?? [];
    }

    // ── Step 3: Synthesize answer with Claude ─────────────────────────────────

    /**
     * Sends the top results to Claude and returns a synthesized prose answer.
     *
     * @param  array<int,array<string,mixed>> $results  rows from searchSupabase()
     * @throws SynthesisException on API error or missing key
     */
    public function synthesizeAnswer(string $query, array $results): string
    {
        if (!$this->anthropicKey) {
            throw new SynthesisException('ANTHROPIC_API_KEY not configured.');
        }

        if (empty($results)) {
            return "No relevant documents were found in the OHS knowledge base for this query. "
                 . "This may mean the topic hasn't been ingested yet, or try rephrasing.";
        }

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
            'model'      => $this->synthesisModel,
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
                'x-api-key: ' . $this->anthropicKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new SynthesisException("Claude connection error: $curlErr");
        }
        if ($httpCode !== 200) {
            throw new SynthesisException("Claude synthesis failed (HTTP $httpCode): $response");
        }

        $data = json_decode($response, true);
        $text = $data['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new SynthesisException("Claude returned no text content: $response");
        }
        return $text;
    }

    // ── Step 4: Log query (fire-and-forget) ───────────────────────────────────

    /**
     * Writes a row to query_log.  Never throws — logging must not break search.
     *
     * @param int  $resultCount  number of chunks returned
     * @param bool $hadAnswer    whether Claude synthesis ran
     */
    public function logQuery(string $query, int $resultCount, bool $hadAnswer = false): void
    {
        $payload = json_encode([
            'query_text'   => $query,
            'result_count' => $resultCount,
            'had_answer'   => $hadAnswer,
        ]);

        $ch = curl_init($this->supabaseUrl . '/rest/v1/query_log');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,          // fire-and-forget — short timeout
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: '         . $this->supabaseAnonKey,
                'Authorization: Bearer ' . $this->supabaseAnonKey,
                'Prefer: return=minimal',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        curl_exec($ch);   // ignore response
        curl_close($ch);
    }
}

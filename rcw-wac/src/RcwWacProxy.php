<?php
/**
 * RCW/WAC Legal RAG — RcwWacProxy
 *
 * Handles the two synchronous steps that happen before the Claude stream starts:
 *   1. getEmbedding()   — embed the user query via OpenAI
 *   2. searchSupabase() — vector+BM25 hybrid search via search_rcw_wac() RPC
 *
 * api-proxy.php stays thin: load secrets → build proxy → embed+search → stream Claude.
 */

namespace RcwWac;

class EmbeddingException extends \RuntimeException {}
class SupabaseException  extends \RuntimeException {
    public function __construct(string $message, public readonly int $httpCode = 0) {
        parent::__construct($message);
    }
}

class RcwWacProxy
{
    private string  $openaiKey;
    private string  $supabaseUrl;
    private string  $supabaseAnonKey;
    private string  $embeddingModel;

    public function __construct(array $config)
    {
        $this->openaiKey       = $config['OPENAI_API_KEY']    ?? '';
        $this->supabaseUrl     = rtrim($config['SUPABASE_URL'] ?? '', '/');
        $this->supabaseAnonKey = $config['SUPABASE_ANON_KEY'] ?? '';
        $this->embeddingModel  = $config['EMBEDDING_MODEL']   ?? 'text-embedding-3-small';
    }

    // ── Step 1: Embed query ───────────────────────────────────────────────────

    /**
     * Calls OpenAI /v1/embeddings and returns the 1536-dimensional vector.
     *
     * @return float[]
     * @throws EmbeddingException
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

        $data      = json_decode($response, true);
        $embedding = $data['data'][0]['embedding'] ?? null;
        if (!is_array($embedding) || empty($embedding)) {
            throw new EmbeddingException("OpenAI returned no embedding data.");
        }
        return $embedding;
    }

    // ── Step 2: Search Supabase ───────────────────────────────────────────────

    /**
     * Calls search_rcw_wac() RPC via Supabase REST.
     *
     * @param  float[]    $embedding   1536-dim query vector
     * @param  string|null $corpus     'rcw' | 'wac' | null (both)
     * @param  int         $limit      max results (1–20)
     * @param  string|null $queryText  enables BM25 lane when supplied
     * @return array<int,array<string,mixed>>
     * @throws SupabaseException
     */
    public function searchSupabase(
        array   $embedding,
        ?string $corpus,
        int     $limit       = 8,
        ?string $queryText   = null,
        float   $minSimilarity = 0.25
    ): array {
        $params = [
            'query_embedding' => $embedding,
            'match_count'     => max(1, min($limit, 20)),
            'min_similarity'  => $minSimilarity,
        ];
        if ($corpus && in_array($corpus, ['rcw', 'wac', 'usc', 'cfr', 'state', 'federal'], true)) {
            $params['filter_corpus'] = $corpus;
        }
        if ($queryText) {
            $params['query_text'] = $queryText;
        }

        $ch = curl_init($this->supabaseUrl . '/rest/v1/rpc/search_rcw_wac');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: '             . $this->supabaseAnonKey,
                'Authorization: Bearer ' . $this->supabaseAnonKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($params),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new SupabaseException("Supabase connection error: $curlErr");
        }
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new SupabaseException(
                "Supabase search failed (HTTP $httpCode): " . ($err['message'] ?? $response),
                $httpCode
            );
        }

        return json_decode($response, true) ?? [];
    }

    // ── Build context block for Claude ───────────────────────────────────────

    /**
     * Format retrieved sections into a context string for Claude's prompt.
     * Returns both the formatted string and a lightweight sources array for the UI.
     *
     * @param  array<int,array<string,mixed>> $results  rows from searchSupabase()
     * @return array{ context: string, sources: list<array> }
     */
    public function buildContext(array $results): array
    {
        $context = '';
        $sources = [];

        foreach ($results as $i => $r) {
            $sectionId   = $r['section_id']      ?? '';
            $heading     = $r['section_heading'] ?? '';
            $corpusLabel = match($r['corpus'] ?? '') {
                'rcw' => 'RCW', 'wac' => 'WAC',
                'usc' => 'USC', 'cfr' => 'CFR',
                default => strtoupper($r['corpus'] ?? ''),
            };
            $titleName   = $r['title_name']   ?? '';
            $chapterName = $r['chapter_name'] ?? '';
            $content     = $r['content']      ?? '';
            $sourceUrl   = $r['source_url']   ?? '';
            $similarity  = isset($r['similarity']) ? round((float)$r['similarity'] * 100) : null;

            // Context for Claude
            $label   = $sectionId . ($heading ? " — $heading" : '');
            $context .= "[$label]\n$content\n\n";

            // Lightweight source card for the UI
            $sources[] = [
                'section_id'      => $sectionId,
                'section_heading' => $heading,
                'corpus'          => $r['corpus'] ?? '',
                'corpus_label'    => $corpusLabel,
                'title_name'      => $titleName,
                'chapter_name'    => $chapterName,
                'source_url'      => $sourceUrl,
                'similarity_pct'  => $similarity,
            ];
        }

        return ['context' => trim($context), 'sources' => $sources];
    }

    // ── Log query — returns inserted row ID ──────────────────────────────────

    public function logQuery(string $query, ?string $corpus, int $resultCount): int
    {
        $payload = json_encode([
            'query_text'    => $query,
            'corpus_filter' => $corpus,
            'result_count'  => $resultCount,
        ]);

        $ch = curl_init($this->supabaseUrl . '/rest/v1/rcw_wac_query_log');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: '             . $this->supabaseAnonKey,
                'Authorization: Bearer ' . $this->supabaseAnonKey,
                'Prefer: return=representation',  // get ID back
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $rows = json_decode($result, true) ?? [];
        return (int)($rows[0]['id'] ?? 0);
    }

    // ── Patch token counts onto an existing log row ───────────────────────────

    public function logTokens(int $logId, array $data): void
    {
        if ($logId <= 0) return;

        $payload = [];
        foreach (['in_tokens', 'out_tokens', 'cached_tokens', 'continue_count'] as $field) {
            if (isset($data[$field])) {
                $payload[$field] = (int)$data[$field];
            }
        }
        if (empty($payload)) return;

        $ch = curl_init($this->supabaseUrl . '/rest/v1/rcw_wac_query_log?id=eq.' . $logId);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: '             . $this->supabaseAnonKey,
                'Authorization: Bearer ' . $this->supabaseAnonKey,
                'Prefer: return=minimal',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

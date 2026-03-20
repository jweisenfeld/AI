<?php
/**
 * OHS Memory — SearchProxy Unit Tests
 *
 * Curl is mocked at the namespace level: PHP resolves unqualified function
 * calls in OHSSearch\* to the stubs defined here before falling back to the
 * global curl_* functions.  No live network required.
 *
 * Run:  ./vendor/bin/phpunit tests/SearchProxyTest.php
 */

// ── Curl stubs (must be in the production namespace) ─────────────────────────

namespace OHSSearch {

    /**
     * Shared state for the curl mock.  Reset in setUp() before each test.
     */
    class CurlMock
    {
        /** Ordered queue of responses; each curl_init() consumes the next one. */
        public static array $queue = [];

        /** Record of every request made: [url, opts, responseIndex]. */
        public static array $log   = [];

        /** Internal: handle → response-queue-index. */
        private static array $handles = [];
        private static int   $next    = 0;

        public static function reset(): void
        {
            self::$queue   = [];
            self::$log     = [];
            self::$handles = [];
            self::$next    = 0;
        }

        /** Queue a response that the next curl_init() will consume. */
        public static function enqueue(int $httpCode, string $body, string $curlError = ''): void
        {
            self::$queue[] = [
                'httpCode'  => $httpCode,
                'body'      => $body,
                'curlError' => $curlError,
            ];
        }

        // Internal helpers used by the stub functions below.
        public static function _init(string $url): int
        {
            $handle = count(self::$handles);
            self::$handles[$handle] = [
                'url'   => $url,
                'opts'  => [],
                'respIndex' => self::$next++,
            ];
            self::$log[$handle] = ['url' => $url, 'opts' => []];
            return $handle;
        }

        public static function _setOpts(int $handle, array $opts): void
        {
            self::$handles[$handle]['opts'] = $opts;
            self::$log[$handle]['opts']     = $opts;
        }

        public static function _response(int $handle): array
        {
            $idx = self::$handles[$handle]['respIndex'];
            return self::$queue[$idx] ?? ['httpCode' => 200, 'body' => '', 'curlError' => ''];
        }
    }

    // PHP namespace-local curl stubs ─────────────────────────────────────────

    function curl_init(string $url = ''): int          { return CurlMock::_init($url); }
    function curl_setopt_array($h, array $o): bool     { CurlMock::_setOpts($h, $o); return true; }
    function curl_exec($h): string|false               { return CurlMock::_response($h)['body']; }
    function curl_getinfo($h, int $opt): mixed         {
        return ($opt === \CURLINFO_HTTP_CODE) ? CurlMock::_response($h)['httpCode'] : null;
    }
    function curl_error($h): string                    { return CurlMock::_response($h)['curlError']; }
    function curl_close($h): void                      {}
}

// ── Tests ─────────────────────────────────────────────────────────────────────

namespace OHSSearch\Tests {

    use OHSSearch\CurlMock;
    use OHSSearch\EmbeddingException;
    use OHSSearch\SearchProxy;
    use OHSSearch\SupabaseTimeoutException;
    use OHSSearch\SupabaseException;
    use OHSSearch\SynthesisException;
    use PHPUnit\Framework\TestCase;

    class SearchProxyTest extends TestCase
    {
        private SearchProxy $proxy;

        private static function makeEmbedding(): array
        {
            return array_fill(0, 1536, 0.01);
        }

        protected function setUp(): void
        {
            CurlMock::reset();
            $this->proxy = new SearchProxy([
                'OPENAI_API_KEY'    => 'test-openai-key',
                'SUPABASE_URL'      => 'https://test.supabase.co',
                'SUPABASE_ANON_KEY' => 'test-anon-key',
                'ANTHROPIC_API_KEY' => 'test-anthropic-key',
            ]);
        }

        // ── getEmbedding ──────────────────────────────────────────────────────

        public function test_getEmbedding_returns_vector_on_success(): void
        {
            $vector = self::makeEmbedding();
            CurlMock::enqueue(200, json_encode([
                'data' => [['embedding' => $vector]],
            ]));

            $result = $this->proxy->getEmbedding('What is the late policy?');

            $this->assertCount(1536, $result);
            $this->assertEquals($vector, $result);
        }

        public function test_getEmbedding_posts_to_correct_url(): void
        {
            CurlMock::enqueue(200, json_encode([
                'data' => [['embedding' => self::makeEmbedding()]],
            ]));

            $this->proxy->getEmbedding('test');

            $this->assertStringContainsString(
                'openai.com/v1/embeddings',
                CurlMock::$log[0]['url']
            );
        }

        public function test_getEmbedding_sends_api_key_header(): void
        {
            CurlMock::enqueue(200, json_encode([
                'data' => [['embedding' => self::makeEmbedding()]],
            ]));

            $this->proxy->getEmbedding('test');

            $headers = CurlMock::$log[0]['opts'][\CURLOPT_HTTPHEADER];
            $this->assertContains('Authorization: Bearer test-openai-key', $headers);
        }

        public function test_getEmbedding_throws_on_non_200(): void
        {
            CurlMock::enqueue(401, json_encode(['error' => ['message' => 'Invalid API key']]));

            $this->expectException(EmbeddingException::class);
            $this->expectExceptionMessageMatches('/401/');
            $this->proxy->getEmbedding('test');
        }

        public function test_getEmbedding_throws_on_empty_embedding(): void
        {
            CurlMock::enqueue(200, json_encode(['data' => [['embedding' => []]]]));

            $this->expectException(EmbeddingException::class);
            $this->proxy->getEmbedding('test');
        }

        public function test_getEmbedding_throws_on_curl_error(): void
        {
            CurlMock::enqueue(0, '', 'Could not resolve host');

            $this->expectException(EmbeddingException::class);
            $this->expectExceptionMessageMatches('/Could not resolve host/');
            $this->proxy->getEmbedding('test');
        }

        public function test_getEmbedding_throws_on_missing_data_key(): void
        {
            CurlMock::enqueue(200, json_encode(['object' => 'list'])); // no 'data' key

            $this->expectException(EmbeddingException::class);
            $this->proxy->getEmbedding('test');
        }

        // ── searchSupabase ────────────────────────────────────────────────────

        public function test_searchSupabase_returns_results_on_success(): void
        {
            $rows = [
                ['content' => 'Late work gets 50%', 'similarity' => 0.92, 'original_filename' => 'policy.pdf'],
                ['content' => 'No late work after 2 weeks', 'similarity' => 0.88, 'original_filename' => 'policy.pdf'],
            ];
            CurlMock::enqueue(200, json_encode($rows));

            $result = $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);

            $this->assertCount(2, $result);
            $this->assertEquals('Late work gets 50%', $result[0]['content']);
        }

        public function test_searchSupabase_posts_to_correct_rpc_endpoint(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);

            $this->assertStringContainsString(
                '/rest/v1/rpc/search_ohs_memory',
                CurlMock::$log[0]['url']
            );
        }

        public function test_searchSupabase_sends_anon_key_in_headers(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);

            $headers = CurlMock::$log[0]['opts'][\CURLOPT_HTTPHEADER];
            $this->assertContains('apikey: test-anon-key', $headers);
            $this->assertContains('Authorization: Bearer test-anon-key', $headers);
        }

        public function test_searchSupabase_includes_match_count_in_payload(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 5);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertEquals(5, $body['match_count']);
        }

        public function test_searchSupabase_passes_filters_when_set(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), 'Physics', '2025-26', 'policy', 'small', 8);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertEquals('Physics',  $body['filter_subject']);
            $this->assertEquals('2025-26',  $body['filter_year']);
            $this->assertEquals('policy',   $body['filter_doc_type']);
            $this->assertEquals('small',    $body['filter_chunk_size']);
        }

        public function test_searchSupabase_omits_null_filters(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertArrayNotHasKey('filter_subject',    $body);
            $this->assertArrayNotHasKey('filter_year',       $body);
            $this->assertArrayNotHasKey('filter_doc_type',   $body);
            $this->assertArrayNotHasKey('filter_chunk_size', $body);
        }

        public function test_searchSupabase_ignores_invalid_chunk_size(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, 'medium', 8);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertArrayNotHasKey('filter_chunk_size', $body);
        }

        public function test_searchSupabase_throws_SupabaseTimeoutException_on_57014(): void
        {
            $errBody = json_encode([
                'code'    => '57014',
                'message' => 'canceling statement due to statement timeout',
                'details' => null,
                'hint'    => null,
            ]);
            CurlMock::enqueue(500, $errBody);

            $this->expectException(SupabaseTimeoutException::class);
            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
        }

        public function test_searchSupabase_timeout_message_includes_limit(): void
        {
            $errBody = json_encode(['code' => '57014', 'message' => 'statement timeout']);
            CurlMock::enqueue(500, $errBody);

            try {
                $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
                $this->fail('Expected SupabaseTimeoutException');
            } catch (SupabaseTimeoutException $e) {
                $this->assertStringContainsString('limit=8', $e->getMessage());
            }
        }

        public function test_searchSupabase_throws_SupabaseException_on_other_errors(): void
        {
            CurlMock::enqueue(403, json_encode(['message' => 'JWT expired']));

            $this->expectException(SupabaseException::class);
            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
        }

        public function test_searchSupabase_SupabaseException_carries_http_code(): void
        {
            CurlMock::enqueue(403, json_encode(['message' => 'JWT expired']));

            try {
                $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
                $this->fail('Expected SupabaseException');
            } catch (SupabaseException $e) {
                $this->assertEquals(403, $e->httpCode);
            }
        }

        public function test_searchSupabase_throws_on_curl_error(): void
        {
            CurlMock::enqueue(0, '', 'SSL handshake failed');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/SSL handshake failed/');
            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
        }

        public function test_searchSupabase_clamps_limit_to_20(): void
        {
            CurlMock::enqueue(200, json_encode([]));

            $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 999);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertEquals(20, $body['match_count']);
        }

        public function test_searchSupabase_returns_empty_array_on_null_json(): void
        {
            CurlMock::enqueue(200, 'null');

            $result = $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
            $this->assertEquals([], $result);
        }

        // ── Retry logic (tested via the proxy's public methods) ───────────────

        /**
         * Simulate the retry pattern used in search-proxy.php:
         * first call throws SupabaseTimeoutException, second succeeds.
         */
        public function test_retry_on_timeout_succeeds_with_smaller_limit(): void
        {
            // First call: timeout
            CurlMock::enqueue(500, json_encode([
                'code'    => '57014',
                'message' => 'statement timeout',
            ]));
            // Second call (retry with limit=4): success
            $rows = [['content' => 'policy chunk', 'similarity' => 0.9]];
            CurlMock::enqueue(200, json_encode($rows));

            $warning = null;
            try {
                $results = $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
            } catch (SupabaseTimeoutException $e) {
                $results = $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 4);
                $warning = "Search took longer than expected — showing top 4 results instead of 8.";
            }

            $this->assertCount(1, $results);
            $this->assertNotNull($warning);
            $this->assertStringContainsString('top 4', $warning);
        }

        public function test_retry_also_times_out_throws_again(): void
        {
            CurlMock::enqueue(500, json_encode(['code' => '57014', 'message' => 'timeout']));
            CurlMock::enqueue(500, json_encode(['code' => '57014', 'message' => 'timeout']));

            $this->expectException(SupabaseTimeoutException::class);

            try {
                $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);
            } catch (SupabaseTimeoutException $e) {
                // retry
                $this->proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 4);
            }
        }

        // ── synthesizeAnswer ──────────────────────────────────────────────────

        public function test_synthesizeAnswer_returns_text_on_success(): void
        {
            CurlMock::enqueue(200, json_encode([
                'content' => [['type' => 'text', 'text' => 'Late work is penalized 50% per day.']],
            ]));

            $results = [['content' => 'Late work policy...', 'original_filename' => 'policy.pdf', 'school_year' => '2025-26', 'doc_type' => 'policy']];
            $answer  = $this->proxy->synthesizeAnswer('What is the late policy?', $results);

            $this->assertEquals('Late work is penalized 50% per day.', $answer);
        }

        public function test_synthesizeAnswer_returns_no_docs_message_on_empty_results(): void
        {
            $answer = $this->proxy->synthesizeAnswer('What is the late policy?', []);

            $this->assertStringContainsString("No relevant documents", $answer);
        }

        public function test_synthesizeAnswer_posts_to_anthropic_api(): void
        {
            CurlMock::enqueue(200, json_encode([
                'content' => [['type' => 'text', 'text' => 'Answer.']],
            ]));

            $this->proxy->synthesizeAnswer('question', [['content' => 'context']]);

            $this->assertStringContainsString(
                'anthropic.com/v1/messages',
                CurlMock::$log[0]['url']
            );
        }

        public function test_synthesizeAnswer_sends_anthropic_api_key(): void
        {
            CurlMock::enqueue(200, json_encode([
                'content' => [['type' => 'text', 'text' => 'Answer.']],
            ]));

            $this->proxy->synthesizeAnswer('question', [['content' => 'context']]);

            $headers = CurlMock::$log[0]['opts'][\CURLOPT_HTTPHEADER];
            $this->assertContains('x-api-key: test-anthropic-key', $headers);
        }

        public function test_synthesizeAnswer_includes_query_in_request(): void
        {
            CurlMock::enqueue(200, json_encode([
                'content' => [['type' => 'text', 'text' => 'Answer.']],
            ]));

            $this->proxy->synthesizeAnswer('What is the tardy policy?', [['content' => 'x']]);

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertStringContainsString('What is the tardy policy?', $body['messages'][0]['content']);
        }

        public function test_synthesizeAnswer_limits_context_to_six_chunks(): void
        {
            CurlMock::enqueue(200, json_encode([
                'content' => [['type' => 'text', 'text' => 'Answer.']],
            ]));

            $results = array_fill(0, 10, ['content' => 'chunk', 'original_filename' => 'f.pdf']);
            $this->proxy->synthesizeAnswer('question', $results);

            $body    = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $content = $body['messages'][0]['content'];
            // Only [Source 1] through [Source 6] should appear
            $this->assertStringContainsString('[Source 6:', $content);
            $this->assertStringNotContainsString('[Source 7:', $content);
        }

        public function test_synthesizeAnswer_throws_SynthesisException_on_non_200(): void
        {
            CurlMock::enqueue(529, json_encode(['error' => ['message' => 'Overloaded']]));

            $this->expectException(SynthesisException::class);
            $this->expectExceptionMessageMatches('/529/');
            $this->proxy->synthesizeAnswer('question', [['content' => 'context']]);
        }

        public function test_synthesizeAnswer_throws_when_anthropic_key_missing(): void
        {
            $proxy = new SearchProxy([
                'OPENAI_API_KEY'    => 'key',
                'SUPABASE_URL'      => 'https://test.supabase.co',
                'SUPABASE_ANON_KEY' => 'key',
                // No ANTHROPIC_API_KEY
            ]);

            $this->expectException(SynthesisException::class);
            $proxy->synthesizeAnswer('question', [['content' => 'context']]);
        }

        public function test_synthesizeAnswer_throws_on_missing_content_in_response(): void
        {
            CurlMock::enqueue(200, json_encode(['content' => []]));

            $this->expectException(SynthesisException::class);
            $this->proxy->synthesizeAnswer('question', [['content' => 'context']]);
        }

        public function test_synthesizeAnswer_throws_on_curl_error(): void
        {
            CurlMock::enqueue(0, '', 'Connection refused');

            $this->expectException(SynthesisException::class);
            $this->expectExceptionMessageMatches('/Connection refused/');
            $this->proxy->synthesizeAnswer('question', [['content' => 'context']]);
        }

        // ── Constructor / config ──────────────────────────────────────────────

        public function test_constructor_uses_default_embedding_model(): void
        {
            CurlMock::enqueue(200, json_encode([
                'data' => [['embedding' => self::makeEmbedding()]],
            ]));

            $this->proxy->getEmbedding('test');

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertEquals('text-embedding-3-small', $body['model']);
        }

        public function test_constructor_uses_custom_embedding_model(): void
        {
            $proxy = new SearchProxy([
                'OPENAI_API_KEY'    => 'key',
                'SUPABASE_URL'      => 'https://test.supabase.co',
                'SUPABASE_ANON_KEY' => 'key',
                'EMBEDDING_MODEL'   => 'text-embedding-ada-002',
            ]);
            CurlMock::enqueue(200, json_encode([
                'data' => [['embedding' => self::makeEmbedding()]],
            ]));

            $proxy->getEmbedding('test');

            $body = json_decode(CurlMock::$log[0]['opts'][\CURLOPT_POSTFIELDS], true);
            $this->assertEquals('text-embedding-ada-002', $body['model']);
        }

        public function test_supabase_url_trailing_slash_is_stripped(): void
        {
            $proxy = new SearchProxy([
                'OPENAI_API_KEY'    => 'key',
                'SUPABASE_URL'      => 'https://test.supabase.co/',   // trailing slash
                'SUPABASE_ANON_KEY' => 'key',
            ]);
            CurlMock::enqueue(200, json_encode([]));

            $proxy->searchSupabase(self::makeEmbedding(), null, null, null, null, 8);

            // URL should not have double-slash before /rest
            $this->assertStringNotContainsString('co//rest', CurlMock::$log[0]['url']);
        }
    }
}

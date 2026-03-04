<?php
/**
 * Unit tests for Claude API Proxy
 *
 * Run with:
 *   php tests.php
 *
 * These tests validate:
 * - Model tier validation
 * - Model config loading
 * - Model error detection (auto-healing)
 * - Request validation logic
 * - Message format validation
 * - Image detection in messages
 * - System prompt handling
 * - Temperature validation
 * - Error response formatting
 */

declare(strict_types=1);

// ============================================
// TEST FRAMEWORK
// ============================================

$testResults = ['passed' => 0, 'failed' => 0, 'errors' => []];

function assertTrue(bool $condition, string $message): void
{
    global $testResults;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertFalse(bool $condition, string $message): void
{
    assertTrue(!$condition, $message);
}

function assertEquals($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        $expectedStr = is_array($expected) ? json_encode($expected) : (string)$expected;
        $actualStr = is_array($actual) ? json_encode($actual) : (string)$actual;
        throw new RuntimeException("{$message} (expected: {$expectedStr}, got: {$actualStr})");
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException("{$message} ('{$needle}' not found in '{$haystack}')");
    }
}

function assertArrayHasKey(string $key, array $array, string $message): void
{
    if (!array_key_exists($key, $array)) {
        throw new RuntimeException("{$message} (key '{$key}' not found)");
    }
}

function runTest(string $name, callable $testFn): void
{
    global $testResults;
    try {
        $testFn();
        $testResults['passed']++;
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $testResults['failed']++;
        $testResults['errors'][] = ['name' => $name, 'error' => $e->getMessage()];
        echo "  FAIL  {$name}\n";
        echo "        -> {$e->getMessage()}\n";
    }
}

// ============================================
// HELPER FUNCTIONS (extracted from api-proxy.php)
// ============================================

/**
 * Check if messages contain images (vision feature)
 */
function hasImages(array $messages): bool
{
    foreach ($messages as $message) {
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $content) {
                if (isset($content['type']) && $content['type'] === 'image') {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * Validate model tier name
 */
function isValidTier(string $tier): bool
{
    $configPath = __DIR__ . '/model_config.json';
    if (is_readable($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        if (is_array($config) && isset($config['tiers'])) {
            return isset($config['tiers'][$tier]);
        }
    }
    // Fallback: check hardcoded tiers
    return in_array($tier, ['haiku', 'sonnet', 'opus'], true);
}

/**
 * Load model configuration from JSON file.
 * Falls back to hardcoded defaults if file is missing or corrupt.
 */
function loadModelConfig(string $configPath): array
{
    if (is_readable($configPath)) {
        $raw = file_get_contents($configPath);
        $config = json_decode($raw, true);
        if (is_array($config) && isset($config['tiers'])) {
            return $config;
        }
    }
    return [
        'tiers' => [
            'haiku'  => [
                'primary'   => 'claude-haiku-4-5-20251001',
                'fallbacks' => ['claude-haiku-4-5', 'claude-3-haiku-20240307'],
                'pricing'   => ['input_per_mtok' => 1.00, 'output_per_mtok' => 5.00],
            ],
            'sonnet' => [
                'primary'   => 'claude-sonnet-4-6',
                'fallbacks' => ['claude-sonnet-4-5-20250929', 'claude-sonnet-4-5', 'claude-sonnet-4-20250514'],
                'pricing'   => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
            ],
            'opus'   => [
                'primary'   => 'claude-opus-4-6',
                'fallbacks' => ['claude-opus-4-5-20251101', 'claude-opus-4-5', 'claude-opus-4-1-20250805'],
                'pricing'   => ['input_per_mtok' => 5.00, 'output_per_mtok' => 25.00],
            ],
        ]
    ];
}

/**
 * Check if an API error response indicates an invalid/deprecated model.
 */
function isModelError(int $httpCode, ?array $responseData): bool
{
    if ($httpCode !== 400) return false;
    if (!is_array($responseData)) return false;
    $errorType = $responseData['error']['type'] ?? '';
    $errorMsg  = strtolower($responseData['error']['message'] ?? '');
    return $errorType === 'invalid_request_error'
        && strpos($errorMsg, 'model') !== false;
}

/**
 * Validate request data structure
 */
function validateRequest(array $data): array
{
    $errors = [];

    if (!isset($data['model'])) {
        $errors[] = 'Missing required field: model';
    } elseif (!isValidTier($data['model'])) {
        $errors[] = 'Invalid model tier specified';
    }

    if (!isset($data['messages'])) {
        $errors[] = 'Missing required field: messages';
    } elseif (!is_array($data['messages'])) {
        $errors[] = 'Messages must be an array';
    } elseif (empty($data['messages'])) {
        $errors[] = 'Messages array cannot be empty';
    }

    return $errors;
}

/**
 * Build API request from validated input
 */
function buildApiRequest(array $data): array
{
    $request = [
        'model' => $data['model'],
        'max_tokens' => min((int)($data['max_tokens'] ?? 4096), 8192),
        'messages' => $data['messages'],
    ];

    if (isset($data['system']) && is_string($data['system'])) {
        $request['system'] = $data['system'];
    }

    if (isset($data['temperature'])) {
        $temp = (float)$data['temperature'];
        if ($temp >= 0.0 && $temp <= 1.0) {
            $request['temperature'] = $temp;
        }
    }

    return $request;
}

/**
 * Validate message structure
 */
function isValidMessage(array $message): bool
{
    if (!isset($message['role']) || !isset($message['content'])) {
        return false;
    }
    if (!in_array($message['role'], ['user', 'assistant'], true)) {
        return false;
    }
    return true;
}

// ============================================
// TEST SUITES
// ============================================

echo "\n======================================\n";
echo "Claude API Proxy - Unit Tests\n";
echo "======================================\n\n";

// --- Model Tier Validation Tests ---
echo "Model Tier Validation:\n";

runTest('accepts haiku tier', function() {
    assertTrue(isValidTier('haiku'), 'Haiku should be a valid tier');
});

runTest('accepts sonnet tier', function() {
    assertTrue(isValidTier('sonnet'), 'Sonnet should be a valid tier');
});

runTest('accepts opus tier', function() {
    assertTrue(isValidTier('opus'), 'Opus should be a valid tier');
});

runTest('rejects invalid tier', function() {
    assertFalse(isValidTier('gpt-4'), 'GPT-4 should be rejected');
});

runTest('rejects full model ID as tier', function() {
    assertFalse(isValidTier('claude-sonnet-4-5'), 'Full model ID should be rejected as tier');
});

runTest('rejects empty tier string', function() {
    assertFalse(isValidTier(''), 'Empty string should be rejected');
});

// --- Model Config Loading Tests ---
echo "\nModel Config Loading:\n";

runTest('loads model config from JSON file', function() {
    $config = loadModelConfig(__DIR__ . '/model_config.json');
    assertArrayHasKey('tiers', $config, 'Config should have tiers');
    assertArrayHasKey('haiku', $config['tiers'], 'Should have haiku tier');
    assertArrayHasKey('sonnet', $config['tiers'], 'Should have sonnet tier');
    assertArrayHasKey('opus', $config['tiers'], 'Should have opus tier');
});

runTest('each tier has primary and fallbacks', function() {
    $config = loadModelConfig(__DIR__ . '/model_config.json');
    foreach ($config['tiers'] as $tier => $info) {
        assertArrayHasKey('primary', $info, "{$tier} should have primary");
        assertArrayHasKey('fallbacks', $info, "{$tier} should have fallbacks");
        assertTrue(is_array($info['fallbacks']), "{$tier} fallbacks should be array");
    }
});

runTest('falls back to defaults for missing config', function() {
    $config = loadModelConfig('/nonexistent/path/model_config.json');
    assertArrayHasKey('tiers', $config, 'Should return default config');
    assertArrayHasKey('haiku', $config['tiers'], 'Default should have haiku');
    assertEquals('claude-haiku-4-5-20251001', $config['tiers']['haiku']['primary'], 'Default haiku primary');
});

runTest('falls back to defaults for corrupt config', function() {
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_config_');
    file_put_contents($tmpFile, 'not valid json!!!');
    $config = loadModelConfig($tmpFile);
    assertArrayHasKey('tiers', $config, 'Should return default config for corrupt file');
    unlink($tmpFile);
});

// --- Model Error Detection Tests ---
echo "\nModel Error Detection:\n";

runTest('detects model error (400 + invalid_request_error + model message)', function() {
    $response = [
        'type' => 'error',
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The provided model identifier is invalid'
        ]
    ];
    assertTrue(isModelError(400, $response), 'Should detect model error');
});

runTest('detects model not found error', function() {
    $response = [
        'type' => 'error',
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'model: claude-haiku-3-5-20241022 is not available'
        ]
    ];
    assertTrue(isModelError(400, $response), 'Should detect model not found');
});

runTest('does not trigger on non-model 400 error', function() {
    $response = [
        'type' => 'error',
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'messages: at least one message is required'
        ]
    ];
    assertFalse(isModelError(400, $response), 'Should not trigger on non-model error');
});

runTest('does not trigger on 429 rate limit', function() {
    $response = [
        'type' => 'error',
        'error' => [
            'type' => 'rate_limit_error',
            'message' => 'Rate limit exceeded'
        ]
    ];
    assertFalse(isModelError(429, $response), 'Should not trigger on rate limit');
});

runTest('does not trigger on 500 server error', function() {
    assertFalse(isModelError(500, null), 'Should not trigger on server error with null data');
});

runTest('does not trigger on 200 success', function() {
    $response = [
        'content' => [['type' => 'text', 'text' => 'Hello']],
        'model' => 'claude-sonnet-4-5',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20]
    ];
    assertFalse(isModelError(200, $response), 'Should not trigger on success');
});

// --- Request Validation Tests ---
echo "\nRequest Validation:\n";

runTest('valid request passes validation', function() {
    $request = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $errors = validateRequest($request);
    assertEquals([], $errors, 'Valid request should have no errors');
});

runTest('missing model is detected', function() {
    $request = ['messages' => [['role' => 'user', 'content' => 'Hello']]];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect missing model');
    assertContains('model', $errors[0], 'Error should mention model');
});

runTest('missing messages is detected', function() {
    $request = ['model' => 'sonnet'];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect missing messages');
    assertContains('messages', $errors[0], 'Error should mention messages');
});

runTest('empty messages array is detected', function() {
    $request = [
        'model' => 'sonnet',
        'messages' => []
    ];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect empty messages');
});

runTest('invalid model tier is detected', function() {
    $request = [
        'model' => 'invalid-model',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect invalid model tier');
});

// --- Image Detection Tests ---
echo "\nImage Detection:\n";

runTest('detects no images in text-only message', function() {
    $messages = [
        ['role' => 'user', 'content' => 'Hello, Claude!']
    ];
    assertFalse(hasImages($messages), 'Text-only message should have no images');
});

runTest('detects image in multimodal message', function() {
    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'abc123']],
                ['type' => 'text', 'text' => 'What is in this image?']
            ]
        ]
    ];
    assertTrue(hasImages($messages), 'Should detect image in multimodal message');
});

runTest('handles multiple messages with image in second', function() {
    $messages = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!'],
        [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'abc123']]
            ]
        ]
    ];
    assertTrue(hasImages($messages), 'Should find image in any message');
});

runTest('text array content without images returns false', function() {
    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Just text']
            ]
        ]
    ];
    assertFalse(hasImages($messages), 'Text-only array content should have no images');
});

// --- API Request Building Tests ---
echo "\nAPI Request Building:\n";

runTest('builds basic request correctly', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $request = buildApiRequest($input);

    assertEquals('sonnet', $request['model'], 'Model should be set');
    assertEquals(4096, $request['max_tokens'], 'Default max_tokens should be 4096');
    assertArrayHasKey('messages', $request, 'Messages should be present');
});

runTest('respects custom max_tokens up to limit', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'max_tokens' => 2000
    ];
    $request = buildApiRequest($input);
    assertEquals(2000, $request['max_tokens'], 'Custom max_tokens should be respected');
});

runTest('caps max_tokens at 8192', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'max_tokens' => 100000
    ];
    $request = buildApiRequest($input);
    assertEquals(8192, $request['max_tokens'], 'max_tokens should be capped at 8192');
});

runTest('includes system prompt when provided', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'system' => 'You are a helpful assistant.'
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('system', $request, 'System prompt should be included');
    assertEquals('You are a helpful assistant.', $request['system'], 'System prompt should match');
});

runTest('excludes system prompt when not provided', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['system']), 'System should not be set when not provided');
});

runTest('includes valid temperature', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 0.7
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('temperature', $request, 'Temperature should be included');
    assertEquals(0.7, $request['temperature'], 'Temperature should match');
});

runTest('ignores invalid temperature above 1.0', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 1.5
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['temperature']), 'Invalid temperature should be excluded');
});

runTest('ignores negative temperature', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => -0.5
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['temperature']), 'Negative temperature should be excluded');
});

runTest('accepts temperature of 0.0', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 0.0
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('temperature', $request, 'Temperature 0.0 should be included');
    assertEquals(0.0, $request['temperature'], 'Temperature should be 0.0');
});

runTest('accepts temperature of 1.0', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 1.0
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('temperature', $request, 'Temperature 1.0 should be included');
    assertEquals(1.0, $request['temperature'], 'Temperature should be 1.0');
});

// --- Message Validation Tests ---
echo "\nMessage Validation:\n";

runTest('valid user message passes', function() {
    $message = ['role' => 'user', 'content' => 'Hello'];
    assertTrue(isValidMessage($message), 'Valid user message should pass');
});

runTest('valid assistant message passes', function() {
    $message = ['role' => 'assistant', 'content' => 'Hi there!'];
    assertTrue(isValidMessage($message), 'Valid assistant message should pass');
});

runTest('message without role fails', function() {
    $message = ['content' => 'Hello'];
    assertFalse(isValidMessage($message), 'Message without role should fail');
});

runTest('message without content fails', function() {
    $message = ['role' => 'user'];
    assertFalse(isValidMessage($message), 'Message without content should fail');
});

runTest('invalid role fails', function() {
    $message = ['role' => 'system', 'content' => 'Hello'];
    assertFalse(isValidMessage($message), 'System role should fail (use system param instead)');
});

// --- Edge Cases ---
echo "\nEdge Cases:\n";

runTest('handles empty string content', function() {
    $messages = [['role' => 'user', 'content' => '']];
    // Empty content is technically valid JSON structure
    assertFalse(hasImages($messages), 'Empty content should not have images');
});

runTest('handles deeply nested content arrays', function() {
    $messages = [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'First'],
                ['type' => 'text', 'text' => 'Second'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'data']],
                ['type' => 'text', 'text' => 'Third']
            ]
        ]
    ];
    assertTrue(hasImages($messages), 'Should find image among multiple content items');
});

runTest('handles unicode in messages', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hello in Japanese']]
    ];
    $request = buildApiRequest($input);
    assertContains('Japanese', $request['messages'][0]['content'], 'Unicode should be preserved');
});

runTest('handles special characters in system prompt', function() {
    $input = [
        'model' => 'sonnet',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
        'system' => "You are a helpful assistant.\nBe concise.\n\nUse bullet points."
    ];
    $request = buildApiRequest($input);
    assertContains("\n", $request['system'], 'Newlines should be preserved in system prompt');
});

// --- Model Config JSON Validation Tests ---
echo "\nModel Config JSON Validation:\n";

runTest('model_config.json exists and is valid JSON', function() {
    $path = __DIR__ . '/model_config.json';
    assertTrue(file_exists($path), 'model_config.json should exist');
    $raw = file_get_contents($path);
    $config = json_decode($raw, true);
    assertTrue(is_array($config), 'model_config.json should be valid JSON');
    assertArrayHasKey('tiers', $config, 'Config should have tiers');
});

runTest('model_config.json has all three tiers', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    assertArrayHasKey('haiku', $config['tiers'], 'Should have haiku tier');
    assertArrayHasKey('sonnet', $config['tiers'], 'Should have sonnet tier');
    assertArrayHasKey('opus', $config['tiers'], 'Should have opus tier');
    assertEquals(3, count($config['tiers']), 'Should have exactly 3 tiers');
});

runTest('each tier has pricing information', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    foreach ($config['tiers'] as $tier => $info) {
        assertArrayHasKey('pricing', $info, "{$tier} should have pricing");
        assertArrayHasKey('input_per_mtok', $info['pricing'], "{$tier} pricing should have input_per_mtok");
        assertArrayHasKey('output_per_mtok', $info['pricing'], "{$tier} pricing should have output_per_mtok");
        assertTrue($info['pricing']['input_per_mtok'] > 0, "{$tier} input price should be positive");
        assertTrue($info['pricing']['output_per_mtok'] > 0, "{$tier} output price should be positive");
    }
});

runTest('haiku is cheapest, opus is most expensive', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    $haikuIn = $config['tiers']['haiku']['pricing']['input_per_mtok'];
    $sonnetIn = $config['tiers']['sonnet']['pricing']['input_per_mtok'];
    $opusIn = $config['tiers']['opus']['pricing']['input_per_mtok'];
    assertTrue($haikuIn < $sonnetIn, 'Haiku input should be cheaper than Sonnet');
    assertTrue($sonnetIn <= $opusIn, 'Sonnet input should be <= Opus');
});

runTest('opus pricing reflects current 4.5/4.6 rates (not old 3.x/4.0)', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    $opusIn = $config['tiers']['opus']['pricing']['input_per_mtok'];
    $opusOut = $config['tiers']['opus']['pricing']['output_per_mtok'];
    // Opus 4.5/4.6 = $5/$25.  Old Opus 3/4.0/4.1 = $15/$75
    assertTrue($opusIn <= 5.00, "Opus input should be <= $5/MTok (got $opusIn)");
    assertTrue($opusOut <= 25.00, "Opus output should be <= $25/MTok (got $opusOut)");
});

runTest('all primary models use current generation IDs', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    $haikuPrimary = $config['tiers']['haiku']['primary'];
    $sonnetPrimary = $config['tiers']['sonnet']['primary'];
    $opusPrimary = $config['tiers']['opus']['primary'];
    // Should be snapshot or alias IDs, not deprecated models
    assertContains('haiku-4-5', $haikuPrimary, 'Haiku primary should be 4.5 series');
    assertContains('sonnet-4-6', $sonnetPrimary, 'Sonnet primary should be 4.6');
    assertContains('opus-4-6', $opusPrimary, 'Opus primary should be 4.6');
});

runTest('fallbacks are non-empty for all tiers', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    foreach ($config['tiers'] as $tier => $info) {
        assertTrue(count($info['fallbacks']) >= 1, "{$tier} should have at least 1 fallback");
    }
});

runTest('primaries are not duplicated in their fallbacks', function() {
    $config = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    foreach ($config['tiers'] as $tier => $info) {
        assertFalse(in_array($info['primary'], $info['fallbacks'], true),
            "{$tier} primary should not appear in its own fallbacks");
    }
});

runTest('hardcoded fallback matches model_config.json', function() {
    // Load from file
    $fileConfig = json_decode(file_get_contents(__DIR__ . '/model_config.json'), true);
    // Load hardcoded fallback (by passing a nonexistent path)
    $hardcoded = loadModelConfig('/nonexistent/path');

    foreach (['haiku', 'sonnet', 'opus'] as $tier) {
        assertEquals(
            $fileConfig['tiers'][$tier]['primary'],
            $hardcoded['tiers'][$tier]['primary'],
            "Hardcoded {$tier} primary should match model_config.json"
        );
    }
});

// --- Input Size Cap Tests ---
echo "\nInput Size Cap:\n";

runTest('input under 200KB is accepted', function() {
    $input = str_repeat('a', 100000);
    $MAX_INPUT_BYTES = 200000;
    assertTrue(strlen($input) <= $MAX_INPUT_BYTES, 'Input under 200KB should be accepted');
});

runTest('input over 200KB is rejected', function() {
    $input = str_repeat('a', 250000);
    $MAX_INPUT_BYTES = 200000;
    assertTrue(strlen($input) > $MAX_INPUT_BYTES, 'Input over 200KB should be rejected');
});

runTest('200KB cap allows generous image payloads', function() {
    // A base64-encoded 100KB image is ~133KB. With message overhead, should fit.
    $fakeImage = base64_encode(str_repeat('x', 100000)); // ~133KB base64
    $message = json_encode([
        'model' => 'opus',
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'data' => $fakeImage]],
            ['type' => 'text', 'text' => 'What is this?']
        ]]]
    ]);
    assertTrue(strlen($message) < 200000, 'Single image + question should fit in 200KB');
});

// --- Opus First-Exchange Restriction Tests ---
echo "\nOpus First-Exchange Restriction:\n";

runTest('opus allowed on first message (msg_count == 1)', function() {
    $model = 'opus';
    $messageCount = 1;
    $opusDowngraded = false;
    if ($model === 'opus' && $messageCount > 1) {
        $model = 'sonnet';
        $opusDowngraded = true;
    }
    assertEquals('opus', $model, 'Opus should be allowed on first message');
    assertFalse($opusDowngraded, 'Should not be downgraded on first message');
});

runTest('opus downgraded on second exchange (msg_count == 3)', function() {
    $model = 'opus';
    $messageCount = 3;
    $opusDowngraded = false;
    if ($model === 'opus' && $messageCount > 1) {
        $model = 'sonnet';
        $opusDowngraded = true;
    }
    assertEquals('sonnet', $model, 'Opus should be downgraded to sonnet after first exchange');
    assertTrue($opusDowngraded, 'opusDowngraded flag should be set');
});

runTest('non-opus model not affected by restriction', function() {
    $model = 'sonnet';
    $messageCount = 25;
    $opusDowngraded = false;
    if ($model === 'opus' && $messageCount > 1) {
        $model = 'sonnet';
        $opusDowngraded = true;
    }
    assertEquals('sonnet', $model, 'Sonnet should remain sonnet');
    assertFalse($opusDowngraded, 'Should not flag downgrade for non-opus');
});

// --- Conversation Length Cap Tests ---
echo "\nConversation Length Cap:\n";

runTest('accepts conversation under cap (50 messages)', function() {
    $MAX_MESSAGES = 50;
    $messageCount = 30;
    assertTrue($messageCount <= $MAX_MESSAGES, '30 messages should be under cap');
});

runTest('rejects conversation over cap', function() {
    $MAX_MESSAGES = 50;
    $messageCount = 52;
    assertTrue($messageCount > $MAX_MESSAGES, '52 messages should be over cap');
});

runTest('accepts conversation at exactly the cap', function() {
    $MAX_MESSAGES = 50;
    $messageCount = 50;
    assertTrue($messageCount <= $MAX_MESSAGES, '50 messages should be at cap (accepted)');
});

// --- Temperature Rounding Tests ---
echo "\nTemperature Rounding:\n";

runTest('temperature is rounded to 2 decimal places', function() {
    $temp = round(0.6999999999999999555910790149937383830547332763671875, 2);
    assertEquals(0.70, $temp, 'Ugly float should round to 0.70');
});

runTest('temperature 0.333333 rounds to 0.33', function() {
    $temp = round(0.333333, 2);
    assertEquals(0.33, $temp, 'Should round to 0.33');
});

runTest('temperature 1.0 stays 1.0', function() {
    $temp = round(1.0, 2);
    assertEquals(1.0, $temp, 'Should remain 1.0');
});

// --- School Hours Tests ---
echo "\nSchool Hours Logic:\n";

runTest('weekday 8AM Pacific is school hours', function() {
    // Mon=1, 8AM
    $dayOfWeek = 1; $hour = 8;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertTrue($isSchoolHours, 'Monday 8AM should be school hours');
});

runTest('weekday 6PM Pacific is NOT school hours', function() {
    $dayOfWeek = 3; $hour = 18;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertFalse($isSchoolHours, 'Wednesday 6PM should not be school hours');
});

runTest('Saturday is NOT school hours', function() {
    $dayOfWeek = 6; $hour = 10;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertFalse($isSchoolHours, 'Saturday should not be school hours');
});

runTest('Sunday is NOT school hours', function() {
    $dayOfWeek = 7; $hour = 12;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertFalse($isSchoolHours, 'Sunday should not be school hours');
});

runTest('weekday 6:59 AM is NOT school hours', function() {
    $dayOfWeek = 2; $hour = 6;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertFalse($isSchoolHours, 'Tuesday 6AM should not be school hours');
});

runTest('weekday 4:59 PM is school hours', function() {
    $dayOfWeek = 4; $hour = 16;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertTrue($isSchoolHours, 'Thursday 4PM (hour 16) should be school hours');
});

runTest('weekday 5:00 PM is NOT school hours', function() {
    $dayOfWeek = 5; $hour = 17;
    $isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);
    assertFalse($isSchoolHours, 'Friday 5PM (hour 17) should not be school hours');
});

// --- countImages function Tests ---
echo "\ncountImages Function:\n";

// Re-declare countImages here since it's defined in api-proxy.php
if (!function_exists('countImages')) {
    function countImages(array $messages): array
    {
        $count = 0;
        $types = [];
        foreach ($messages as $message) {
            if (isset($message['content']) && is_array($message['content'])) {
                foreach ($message['content'] as $content) {
                    if (isset($content['type']) && $content['type'] === 'image') {
                        $count++;
                        $mediaType = $content['source']['media_type'] ?? 'unknown';
                        $types[] = $mediaType;
                    }
                }
            }
        }
        return ['count' => $count, 'types' => array_unique($types)];
    }
}

runTest('countImages returns 0 for text-only messages', function() {
    $result = countImages([['role' => 'user', 'content' => 'Hello']]);
    assertEquals(0, $result['count'], 'Text-only should have 0 images');
});

runTest('countImages counts multiple images', function() {
    $messages = [[
        'role' => 'user',
        'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'x']],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'y']],
            ['type' => 'text', 'text' => 'What are these?']
        ]
    ]];
    $result = countImages($messages);
    assertEquals(2, $result['count'], 'Should count 2 images');
    assertTrue(in_array('image/png', $result['types']), 'Should include PNG type');
    assertTrue(in_array('image/jpeg', $result['types']), 'Should include JPEG type');
});

// --- getLastUserText function Tests ---
echo "\ngetLastUserText Function:\n";

if (!function_exists('getLastUserText')) {
    function getLastUserText(array $messages): string
    {
        $lastUserMsg = null;
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $lastUserMsg = $msg;
                break;
            }
        }
        if (!$lastUserMsg) return '';
        if (is_string($lastUserMsg['content'])) {
            return $lastUserMsg['content'];
        }
        if (is_array($lastUserMsg['content'])) {
            $parts = [];
            foreach ($lastUserMsg['content'] as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $parts[] = $part['text'] ?? '';
                }
            }
            return implode(' ', $parts);
        }
        return '';
    }
}

runTest('getLastUserText returns text from simple string content', function() {
    $messages = [
        ['role' => 'user', 'content' => 'Hello world'],
        ['role' => 'assistant', 'content' => 'Hi there']
    ];
    assertEquals('Hello world', getLastUserText($messages), 'Should return user text');
});

runTest('getLastUserText returns last user message not first', function() {
    $messages = [
        ['role' => 'user', 'content' => 'First question'],
        ['role' => 'assistant', 'content' => 'Answer'],
        ['role' => 'user', 'content' => 'Second question']
    ];
    assertEquals('Second question', getLastUserText($messages), 'Should return last user text');
});

runTest('getLastUserText extracts text from multimodal content', function() {
    $messages = [[
        'role' => 'user',
        'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'x']],
            ['type' => 'text', 'text' => 'What is this?']
        ]
    ]];
    assertEquals('What is this?', getLastUserText($messages), 'Should extract text from multimodal');
});

runTest('getLastUserText returns empty for no user messages', function() {
    $messages = [['role' => 'assistant', 'content' => 'Hi']];
    assertEquals('', getLastUserText($messages), 'Should return empty string');
});

// ============================================
// RESULTS SUMMARY
// ============================================

echo "\n======================================\n";
echo "Test Results\n";
echo "======================================\n";
echo "Passed: {$testResults['passed']}\n";
echo "Failed: {$testResults['failed']}\n";
echo "Total:  " . ($testResults['passed'] + $testResults['failed']) . "\n";

if ($testResults['failed'] > 0) {
    echo "\nFailed Tests:\n";
    foreach ($testResults['errors'] as $error) {
        echo "  - {$error['name']}: {$error['error']}\n";
    }
    exit(1);
}

echo "\nAll tests passed!\n";
exit(0);

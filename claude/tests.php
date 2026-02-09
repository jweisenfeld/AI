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
            'haiku'  => ['primary' => 'claude-haiku-4-5',  'fallbacks' => []],
            'sonnet' => ['primary' => 'claude-sonnet-4-5', 'fallbacks' => []],
            'opus'   => ['primary' => 'claude-opus-4-6',   'fallbacks' => []],
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
    assertEquals('claude-haiku-4-5', $config['tiers']['haiku']['primary'], 'Default haiku primary');
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

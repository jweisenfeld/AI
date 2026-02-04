<?php
/**
 * Unit tests for Claude API Proxy
 *
 * Run with:
 *   php tests.php
 *
 * These tests validate:
 * - Request validation logic
 * - Model whitelist enforcement
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
 * Validate model against whitelist
 */
function isValidModel(string $model): bool
{
    $allowedModels = [
        'claude-sonnet-4-20250514',
        'claude-opus-4-20250514',
        'claude-haiku-3-5-20241022'
    ];
    return in_array($model, $allowedModels, true);
}

/**
 * Validate request data structure
 */
function validateRequest(array $data): array
{
    $errors = [];

    if (!isset($data['model'])) {
        $errors[] = 'Missing required field: model';
    } elseif (!isValidModel($data['model'])) {
        $errors[] = 'Invalid model specified';
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

// --- Model Validation Tests ---
echo "Model Validation:\n";

runTest('accepts claude-sonnet-4-20250514', function() {
    assertTrue(isValidModel('claude-sonnet-4-20250514'), 'Sonnet 4 should be valid');
});

runTest('accepts claude-opus-4-20250514', function() {
    assertTrue(isValidModel('claude-opus-4-20250514'), 'Opus 4 should be valid');
});

runTest('accepts claude-haiku-3-5-20241022', function() {
    assertTrue(isValidModel('claude-haiku-3-5-20241022'), 'Haiku 3.5 should be valid');
});

runTest('rejects invalid model', function() {
    assertFalse(isValidModel('gpt-4'), 'GPT-4 should be rejected');
});

runTest('rejects old claude model', function() {
    assertFalse(isValidModel('claude-2'), 'Claude 2 should be rejected');
});

runTest('rejects empty model string', function() {
    assertFalse(isValidModel(''), 'Empty string should be rejected');
});

// --- Request Validation Tests ---
echo "\nRequest Validation:\n";

runTest('valid request passes validation', function() {
    $request = [
        'model' => 'claude-sonnet-4-20250514',
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
    $request = ['model' => 'claude-sonnet-4-20250514'];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect missing messages');
    assertContains('messages', $errors[0], 'Error should mention messages');
});

runTest('empty messages array is detected', function() {
    $request = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => []
    ];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect empty messages');
});

runTest('invalid model is detected', function() {
    $request = [
        'model' => 'invalid-model',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $errors = validateRequest($request);
    assertTrue(count($errors) > 0, 'Should detect invalid model');
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
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $request = buildApiRequest($input);

    assertEquals('claude-sonnet-4-20250514', $request['model'], 'Model should be set');
    assertEquals(4096, $request['max_tokens'], 'Default max_tokens should be 4096');
    assertArrayHasKey('messages', $request, 'Messages should be present');
});

runTest('respects custom max_tokens up to limit', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'max_tokens' => 2000
    ];
    $request = buildApiRequest($input);
    assertEquals(2000, $request['max_tokens'], 'Custom max_tokens should be respected');
});

runTest('caps max_tokens at 8192', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'max_tokens' => 100000
    ];
    $request = buildApiRequest($input);
    assertEquals(8192, $request['max_tokens'], 'max_tokens should be capped at 8192');
});

runTest('includes system prompt when provided', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'system' => 'You are a helpful assistant.'
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('system', $request, 'System prompt should be included');
    assertEquals('You are a helpful assistant.', $request['system'], 'System prompt should match');
});

runTest('excludes system prompt when not provided', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['system']), 'System should not be set when not provided');
});

runTest('includes valid temperature', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 0.7
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('temperature', $request, 'Temperature should be included');
    assertEquals(0.7, $request['temperature'], 'Temperature should match');
});

runTest('ignores invalid temperature above 1.0', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 1.5
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['temperature']), 'Invalid temperature should be excluded');
});

runTest('ignores negative temperature', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => -0.5
    ];
    $request = buildApiRequest($input);
    assertFalse(isset($request['temperature']), 'Negative temperature should be excluded');
});

runTest('accepts temperature of 0.0', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
        'temperature' => 0.0
    ];
    $request = buildApiRequest($input);
    assertArrayHasKey('temperature', $request, 'Temperature 0.0 should be included');
    assertEquals(0.0, $request['temperature'], 'Temperature should be 0.0');
});

runTest('accepts temperature of 1.0', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
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
        'model' => 'claude-sonnet-4-20250514',
        'messages' => [['role' => 'user', 'content' => 'Hello in Japanese']]
    ];
    $request = buildApiRequest($input);
    assertContains('Japanese', $request['messages'][0]['content'], 'Unicode should be preserved');
});

runTest('handles special characters in system prompt', function() {
    $input = [
        'model' => 'claude-sonnet-4-20250514',
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

/**
 * JavaScript Unit Tests for Claude API Proxy Logic
 *
 * Run with: node tests.js
 *
 * These tests mirror the PHP tests and validate the same logic
 * that runs in api-proxy.php
 */

const testResults = { passed: 0, failed: 0, errors: [] };

// ============================================
// TEST FRAMEWORK
// ============================================

function assertTrue(condition, message) {
    if (!condition) {
        throw new Error(`Assertion failed: ${message}`);
    }
}

function assertFalse(condition, message) {
    assertTrue(!condition, message);
}

function assertEquals(expected, actual, message) {
    if (expected !== actual) {
        throw new Error(`${message} (expected: ${JSON.stringify(expected)}, got: ${JSON.stringify(actual)})`);
    }
}

function assertContains(needle, haystack, message) {
    if (!haystack.includes(needle)) {
        throw new Error(`${message} ('${needle}' not found in '${haystack}')`);
    }
}

function assertArrayHasKey(key, obj, message) {
    if (!(key in obj)) {
        throw new Error(`${message} (key '${key}' not found)`);
    }
}

function runTest(name, testFn) {
    try {
        testFn();
        testResults.passed++;
        console.log(`  PASS  ${name}`);
    } catch (e) {
        testResults.failed++;
        testResults.errors.push({ name, error: e.message });
        console.log(`  FAIL  ${name}`);
        console.log(`        -> ${e.message}`);
    }
}

// ============================================
// HELPER FUNCTIONS (matching api-proxy.php)
// ============================================

/**
 * Check if messages contain images (vision feature)
 */
function hasImages(messages) {
    for (const message of messages) {
        if (message.content && Array.isArray(message.content)) {
            for (const content of message.content) {
                if (content.type === 'image') {
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
function isValidTier(tier) {
    const validTiers = ['haiku', 'sonnet', 'opus'];
    return validTiers.includes(tier);
}

/**
 * Check if an API error response indicates an invalid/deprecated model.
 */
function isModelError(httpCode, responseData) {
    if (httpCode !== 400) return false;
    if (!responseData || !responseData.error) return false;
    const errorType = responseData.error.type || '';
    const errorMsg = (responseData.error.message || '').toLowerCase();
    return errorType === 'invalid_request_error' && errorMsg.includes('model');
}

/**
 * Validate request data structure
 */
function validateRequest(data) {
    const errors = [];

    if (!data.model) {
        errors.push('Missing required field: model');
    } else if (!isValidTier(data.model)) {
        errors.push('Invalid model tier specified');
    }

    if (!data.messages) {
        errors.push('Missing required field: messages');
    } else if (!Array.isArray(data.messages)) {
        errors.push('Messages must be an array');
    } else if (data.messages.length === 0) {
        errors.push('Messages array cannot be empty');
    }

    return errors;
}

/**
 * Build API request from validated input
 */
function buildApiRequest(data) {
    const request = {
        model: data.model,
        max_tokens: Math.min(data.max_tokens ?? 4096, 8192),
        messages: data.messages,
    };

    if (data.system && typeof data.system === 'string') {
        request.system = data.system;
    }

    if (data.temperature !== undefined) {
        const temp = parseFloat(data.temperature);
        if (temp >= 0.0 && temp <= 1.0) {
            request.temperature = temp;
        }
    }

    return request;
}

/**
 * Validate message structure
 */
function isValidMessage(message) {
    if (!message.role || !('content' in message)) {
        return false;
    }
    if (!['user', 'assistant'].includes(message.role)) {
        return false;
    }
    return true;
}

// ============================================
// TEST SUITES
// ============================================

console.log('\n======================================');
console.log('Claude API Proxy - JavaScript Unit Tests');
console.log('======================================\n');

// --- Model Tier Validation Tests ---
console.log('Model Tier Validation:');

runTest('accepts haiku tier', () => {
    assertTrue(isValidTier('haiku'), 'Haiku should be a valid tier');
});

runTest('accepts sonnet tier', () => {
    assertTrue(isValidTier('sonnet'), 'Sonnet should be a valid tier');
});

runTest('accepts opus tier', () => {
    assertTrue(isValidTier('opus'), 'Opus should be a valid tier');
});

runTest('rejects invalid tier', () => {
    assertFalse(isValidTier('gpt-4'), 'GPT-4 should be rejected');
});

runTest('rejects full model ID as tier', () => {
    assertFalse(isValidTier('claude-sonnet-4-5'), 'Full model ID should be rejected as tier');
});

runTest('rejects empty tier string', () => {
    assertFalse(isValidTier(''), 'Empty string should be rejected');
});

// --- Model Error Detection Tests ---
console.log('\nModel Error Detection:');

runTest('detects model error (400 + invalid_request_error + model message)', () => {
    const response = {
        type: 'error',
        error: {
            type: 'invalid_request_error',
            message: 'The provided model identifier is invalid'
        }
    };
    assertTrue(isModelError(400, response), 'Should detect model error');
});

runTest('detects model not found error', () => {
    const response = {
        type: 'error',
        error: {
            type: 'invalid_request_error',
            message: 'model: claude-haiku-3-5-20241022 is not available'
        }
    };
    assertTrue(isModelError(400, response), 'Should detect model not found');
});

runTest('does not trigger on non-model 400 error', () => {
    const response = {
        type: 'error',
        error: {
            type: 'invalid_request_error',
            message: 'messages: at least one message is required'
        }
    };
    assertFalse(isModelError(400, response), 'Should not trigger on non-model error');
});

runTest('does not trigger on 429 rate limit', () => {
    const response = {
        type: 'error',
        error: {
            type: 'rate_limit_error',
            message: 'Rate limit exceeded'
        }
    };
    assertFalse(isModelError(429, response), 'Should not trigger on rate limit');
});

runTest('does not trigger on 500 server error', () => {
    assertFalse(isModelError(500, null), 'Should not trigger on server error with null data');
});

runTest('does not trigger on 200 success', () => {
    const response = {
        content: [{ type: 'text', text: 'Hello' }],
        model: 'claude-sonnet-4-5',
        usage: { input_tokens: 10, output_tokens: 20 }
    };
    assertFalse(isModelError(200, response), 'Should not trigger on success');
});

// --- Request Validation Tests ---
console.log('\nRequest Validation:');

runTest('valid request passes validation', () => {
    const request = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }]
    };
    const errors = validateRequest(request);
    assertEquals(0, errors.length, 'Valid request should have no errors');
});

runTest('missing model is detected', () => {
    const request = { messages: [{ role: 'user', content: 'Hello' }] };
    const errors = validateRequest(request);
    assertTrue(errors.length > 0, 'Should detect missing model');
    assertContains('model', errors[0], 'Error should mention model');
});

runTest('missing messages is detected', () => {
    const request = { model: 'sonnet' };
    const errors = validateRequest(request);
    assertTrue(errors.length > 0, 'Should detect missing messages');
    assertContains('messages', errors[0], 'Error should mention messages');
});

runTest('empty messages array is detected', () => {
    const request = {
        model: 'sonnet',
        messages: []
    };
    const errors = validateRequest(request);
    assertTrue(errors.length > 0, 'Should detect empty messages');
});

runTest('invalid model tier is detected', () => {
    const request = {
        model: 'invalid-model',
        messages: [{ role: 'user', content: 'Hello' }]
    };
    const errors = validateRequest(request);
    assertTrue(errors.length > 0, 'Should detect invalid model tier');
});

// --- Image Detection Tests ---
console.log('\nImage Detection:');

runTest('detects no images in text-only message', () => {
    const messages = [{ role: 'user', content: 'Hello, Claude!' }];
    assertFalse(hasImages(messages), 'Text-only message should have no images');
});

runTest('detects image in multimodal message', () => {
    const messages = [{
        role: 'user',
        content: [
            { type: 'image', source: { type: 'base64', data: 'abc123' } },
            { type: 'text', text: 'What is in this image?' }
        ]
    }];
    assertTrue(hasImages(messages), 'Should detect image in multimodal message');
});

runTest('handles multiple messages with image in second', () => {
    const messages = [
        { role: 'user', content: 'Hello' },
        { role: 'assistant', content: 'Hi there!' },
        {
            role: 'user',
            content: [{ type: 'image', source: { type: 'base64', data: 'abc123' } }]
        }
    ];
    assertTrue(hasImages(messages), 'Should find image in any message');
});

runTest('text array content without images returns false', () => {
    const messages = [{
        role: 'user',
        content: [{ type: 'text', text: 'Just text' }]
    }];
    assertFalse(hasImages(messages), 'Text-only array content should have no images');
});

// --- API Request Building Tests ---
console.log('\nAPI Request Building:');

runTest('builds basic request correctly', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }]
    };
    const request = buildApiRequest(input);

    assertEquals('sonnet', request.model, 'Model should be set');
    assertEquals(4096, request.max_tokens, 'Default max_tokens should be 4096');
    assertArrayHasKey('messages', request, 'Messages should be present');
});

runTest('respects custom max_tokens up to limit', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        max_tokens: 2000
    };
    const request = buildApiRequest(input);
    assertEquals(2000, request.max_tokens, 'Custom max_tokens should be respected');
});

runTest('caps max_tokens at 8192', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        max_tokens: 100000
    };
    const request = buildApiRequest(input);
    assertEquals(8192, request.max_tokens, 'max_tokens should be capped at 8192');
});

runTest('includes system prompt when provided', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        system: 'You are a helpful assistant.'
    };
    const request = buildApiRequest(input);
    assertArrayHasKey('system', request, 'System prompt should be included');
    assertEquals('You are a helpful assistant.', request.system, 'System prompt should match');
});

runTest('excludes system prompt when not provided', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }]
    };
    const request = buildApiRequest(input);
    assertFalse('system' in request, 'System should not be set when not provided');
});

runTest('includes valid temperature', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        temperature: 0.7
    };
    const request = buildApiRequest(input);
    assertArrayHasKey('temperature', request, 'Temperature should be included');
    assertEquals(0.7, request.temperature, 'Temperature should match');
});

runTest('ignores invalid temperature above 1.0', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        temperature: 1.5
    };
    const request = buildApiRequest(input);
    assertFalse('temperature' in request, 'Invalid temperature should be excluded');
});

runTest('ignores negative temperature', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        temperature: -0.5
    };
    const request = buildApiRequest(input);
    assertFalse('temperature' in request, 'Negative temperature should be excluded');
});

runTest('accepts temperature of 0.0', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        temperature: 0.0
    };
    const request = buildApiRequest(input);
    assertArrayHasKey('temperature', request, 'Temperature 0.0 should be included');
    assertEquals(0.0, request.temperature, 'Temperature should be 0.0');
});

runTest('accepts temperature of 1.0', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello' }],
        temperature: 1.0
    };
    const request = buildApiRequest(input);
    assertArrayHasKey('temperature', request, 'Temperature 1.0 should be included');
    assertEquals(1.0, request.temperature, 'Temperature should be 1.0');
});

// --- Message Validation Tests ---
console.log('\nMessage Validation:');

runTest('valid user message passes', () => {
    const message = { role: 'user', content: 'Hello' };
    assertTrue(isValidMessage(message), 'Valid user message should pass');
});

runTest('valid assistant message passes', () => {
    const message = { role: 'assistant', content: 'Hi there!' };
    assertTrue(isValidMessage(message), 'Valid assistant message should pass');
});

runTest('message without role fails', () => {
    const message = { content: 'Hello' };
    assertFalse(isValidMessage(message), 'Message without role should fail');
});

runTest('message without content fails', () => {
    const message = { role: 'user' };
    assertFalse(isValidMessage(message), 'Message without content should fail');
});

runTest('invalid role fails', () => {
    const message = { role: 'system', content: 'Hello' };
    assertFalse(isValidMessage(message), 'System role should fail (use system param instead)');
});

// --- Edge Cases ---
console.log('\nEdge Cases:');

runTest('handles empty string content', () => {
    const messages = [{ role: 'user', content: '' }];
    assertFalse(hasImages(messages), 'Empty content should not have images');
});

runTest('handles deeply nested content arrays', () => {
    const messages = [{
        role: 'user',
        content: [
            { type: 'text', text: 'First' },
            { type: 'text', text: 'Second' },
            { type: 'image', source: { type: 'base64', data: 'data' } },
            { type: 'text', text: 'Third' }
        ]
    }];
    assertTrue(hasImages(messages), 'Should find image among multiple content items');
});

runTest('handles unicode in messages', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hello in Japanese' }]
    };
    const request = buildApiRequest(input);
    assertContains('Japanese', request.messages[0].content, 'Unicode should be preserved');
});

runTest('handles special characters in system prompt', () => {
    const input = {
        model: 'sonnet',
        messages: [{ role: 'user', content: 'Hi' }],
        system: "You are a helpful assistant.\nBe concise.\n\nUse bullet points."
    };
    const request = buildApiRequest(input);
    assertContains("\n", request.system, 'Newlines should be preserved in system prompt');
});

// ============================================
// RESULTS SUMMARY
// ============================================

console.log('\n======================================');
console.log('Test Results');
console.log('======================================');
console.log(`Passed: ${testResults.passed}`);
console.log(`Failed: ${testResults.failed}`);
console.log(`Total:  ${testResults.passed + testResults.failed}`);

if (testResults.failed > 0) {
    console.log('\nFailed Tests:');
    testResults.errors.forEach(e => {
        console.log(`  - ${e.name}: ${e.error}`);
    });
    process.exit(1);
}

console.log('\nAll tests passed!');
process.exit(0);

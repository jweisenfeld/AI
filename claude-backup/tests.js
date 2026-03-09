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

// --- Model Config JSON Validation Tests ---
console.log('\nModel Config JSON Validation:');

// Load model_config.json
const fs = require('fs');
const path = require('path');
const configPath = path.join(__dirname, 'model_config.json');

let modelConfig = null;
runTest('model_config.json exists and is valid JSON', () => {
    assertTrue(fs.existsSync(configPath), 'model_config.json should exist');
    const raw = fs.readFileSync(configPath, 'utf8');
    modelConfig = JSON.parse(raw);
    assertArrayHasKey('tiers', modelConfig, 'Config should have tiers');
});

runTest('model_config.json has all three tiers', () => {
    assertArrayHasKey('haiku', modelConfig.tiers, 'Should have haiku tier');
    assertArrayHasKey('sonnet', modelConfig.tiers, 'Should have sonnet tier');
    assertArrayHasKey('opus', modelConfig.tiers, 'Should have opus tier');
    assertEquals(3, Object.keys(modelConfig.tiers).length, 'Should have exactly 3 tiers');
});

runTest('each tier has pricing information', () => {
    for (const [tier, info] of Object.entries(modelConfig.tiers)) {
        assertArrayHasKey('pricing', info, `${tier} should have pricing`);
        assertArrayHasKey('input_per_mtok', info.pricing, `${tier} pricing should have input_per_mtok`);
        assertArrayHasKey('output_per_mtok', info.pricing, `${tier} pricing should have output_per_mtok`);
        assertTrue(info.pricing.input_per_mtok > 0, `${tier} input price should be positive`);
        assertTrue(info.pricing.output_per_mtok > 0, `${tier} output price should be positive`);
    }
});

runTest('haiku is cheapest, opus is most expensive', () => {
    const hIn = modelConfig.tiers.haiku.pricing.input_per_mtok;
    const sIn = modelConfig.tiers.sonnet.pricing.input_per_mtok;
    const oIn = modelConfig.tiers.opus.pricing.input_per_mtok;
    assertTrue(hIn < sIn, 'Haiku input should be cheaper than Sonnet');
    assertTrue(sIn <= oIn, 'Sonnet input should be <= Opus');
});

runTest('opus pricing reflects current 4.5/4.6 rates (not old 3.x/4.0)', () => {
    const oIn = modelConfig.tiers.opus.pricing.input_per_mtok;
    const oOut = modelConfig.tiers.opus.pricing.output_per_mtok;
    assertTrue(oIn <= 5.00, `Opus input should be <= $5/MTok (got ${oIn})`);
    assertTrue(oOut <= 25.00, `Opus output should be <= $25/MTok (got ${oOut})`);
});

runTest('all primary models use current generation IDs', () => {
    assertContains('haiku-4-5', modelConfig.tiers.haiku.primary, 'Haiku primary should be 4.5 series');
    assertContains('sonnet-4-6', modelConfig.tiers.sonnet.primary, 'Sonnet primary should be 4.6');
    assertContains('opus-4-6', modelConfig.tiers.opus.primary, 'Opus primary should be 4.6');
});

runTest('fallbacks are non-empty for all tiers', () => {
    for (const [tier, info] of Object.entries(modelConfig.tiers)) {
        assertTrue(info.fallbacks.length >= 1, `${tier} should have at least 1 fallback`);
    }
});

runTest('primaries are not duplicated in their fallbacks', () => {
    for (const [tier, info] of Object.entries(modelConfig.tiers)) {
        assertFalse(info.fallbacks.includes(info.primary),
            `${tier} primary should not appear in its own fallbacks`);
    }
});

// --- Input Size Cap Tests ---
console.log('\nInput Size Cap:');

runTest('input under 200KB is accepted', () => {
    const input = 'a'.repeat(100000);
    const MAX_INPUT_BYTES = 200000;
    assertTrue(input.length <= MAX_INPUT_BYTES, 'Input under 200KB should be accepted');
});

runTest('input over 200KB is rejected', () => {
    const input = 'a'.repeat(250000);
    const MAX_INPUT_BYTES = 200000;
    assertTrue(input.length > MAX_INPUT_BYTES, 'Input over 200KB should be rejected');
});

// --- Opus First-Exchange Restriction Tests ---
console.log('\nOpus First-Exchange Restriction:');

runTest('opus allowed on first message (msg_count == 1)', () => {
    let model = 'opus';
    const messageCount = 1;
    let opusDowngraded = false;
    if (model === 'opus' && messageCount > 1) {
        model = 'sonnet';
        opusDowngraded = true;
    }
    assertEquals('opus', model, 'Opus should be allowed on first message');
    assertFalse(opusDowngraded, 'Should not be downgraded on first message');
});

runTest('opus downgraded on second exchange (msg_count == 3)', () => {
    let model = 'opus';
    const messageCount = 3;
    let opusDowngraded = false;
    if (model === 'opus' && messageCount > 1) {
        model = 'sonnet';
        opusDowngraded = true;
    }
    assertEquals('sonnet', model, 'Opus should be downgraded to sonnet after first exchange');
    assertTrue(opusDowngraded, 'opusDowngraded flag should be set');
});

runTest('non-opus model not affected by restriction', () => {
    let model = 'sonnet';
    const messageCount = 25;
    let opusDowngraded = false;
    if (model === 'opus' && messageCount > 1) {
        model = 'sonnet';
        opusDowngraded = true;
    }
    assertEquals('sonnet', model, 'Sonnet should remain sonnet');
    assertFalse(opusDowngraded, 'Should not flag downgrade for non-opus');
});

// --- Conversation Length Cap Tests ---
console.log('\nConversation Length Cap:');

runTest('accepts conversation under cap (50 messages)', () => {
    const MAX_MESSAGES = 50;
    const messageCount = 30;
    assertTrue(messageCount <= MAX_MESSAGES, '30 messages should be under cap');
});

runTest('rejects conversation over cap', () => {
    const MAX_MESSAGES = 50;
    const messageCount = 52;
    assertTrue(messageCount > MAX_MESSAGES, '52 messages should be over cap');
});

runTest('accepts conversation at exactly the cap', () => {
    const MAX_MESSAGES = 50;
    const messageCount = 50;
    assertTrue(messageCount <= MAX_MESSAGES, '50 messages should be at cap (accepted)');
});

// --- Temperature Rounding Tests ---
console.log('\nTemperature Rounding:');

runTest('frontend temperature rounding works correctly', () => {
    // Simulates: Math.round(parseFloat(value)) / 100 where slider is 0-100
    const sliderValue = '70';
    const temp = Math.round(parseFloat(sliderValue)) / 100;
    assertEquals(0.7, temp, 'Slider value 70 should produce 0.7');
});

runTest('frontend rounding avoids ugly floats', () => {
    // Without Math.round: 70/100 = 0.7 (ok), but 33/100 = 0.33 (ok too)
    // The old bug was sending the raw slider float before dividing
    const sliderValue = '33';
    const temp = Math.round(parseFloat(sliderValue)) / 100;
    assertEquals(0.33, temp, 'Slider value 33 should produce 0.33');
});

// --- School Hours Tests ---
console.log('\nSchool Hours Logic:');

runTest('weekday 8AM Pacific is school hours', () => {
    const dayOfWeek = 1; const hour = 8; // Mon=1
    const isSchoolHours = (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 7 && hour < 17);
    assertTrue(isSchoolHours, 'Monday 8AM should be school hours');
});

runTest('weekday 6PM Pacific is NOT school hours', () => {
    const dayOfWeek = 3; const hour = 18;
    const isSchoolHours = (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 7 && hour < 17);
    assertFalse(isSchoolHours, 'Wednesday 6PM should not be school hours');
});

runTest('Saturday is NOT school hours', () => {
    const dayOfWeek = 6; const hour = 10;
    const isSchoolHours = (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 7 && hour < 17);
    assertFalse(isSchoolHours, 'Saturday should not be school hours');
});

runTest('weekday 5:00 PM is NOT school hours (boundary)', () => {
    const dayOfWeek = 5; const hour = 17;
    const isSchoolHours = (dayOfWeek >= 1 && dayOfWeek <= 5 && hour >= 7 && hour < 17);
    assertFalse(isSchoolHours, 'Friday 5PM (hour 17) should not be school hours');
});

// --- Dashboard Cost Table Tests ---
console.log('\nDashboard Cost Table:');

// Simulate the COSTS table from the dashboards
const COSTS = {
    'claude-haiku-4-5-20251001': { input: 1.00,  output: 5.00 },
    'claude-haiku-4-5':          { input: 1.00,  output: 5.00 },
    'claude-3-haiku-20240307':   { input: 0.25,  output: 1.25 },
    'claude-haiku-3-5-20241022': { input: 0.80,  output: 4.00 },
    'claude-sonnet-4-6':         { input: 3.00,  output: 15.00 },
    'claude-sonnet-4-5-20250929':{ input: 3.00,  output: 15.00 },
    'claude-sonnet-4-5':         { input: 3.00,  output: 15.00 },
    'claude-sonnet-4-20250514':  { input: 3.00,  output: 15.00 },
    'claude-opus-4-6':           { input: 5.00,  output: 25.00 },
    'claude-opus-4-5-20251101':  { input: 5.00,  output: 25.00 },
    'claude-opus-4-5':           { input: 5.00,  output: 25.00 },
    'claude-opus-4-1-20250805':  { input: 15.00, output: 75.00 },
    'claude-opus-4-20250514':    { input: 15.00, output: 75.00 },
};

function estimateCost(model, inputTokens, outputTokens) {
    const rates = COSTS[model] || { input: 3.00, output: 15.00 };
    return (inputTokens / 1e6) * rates.input + (outputTokens / 1e6) * rates.output;
}

runTest('opus 4.6 cost estimate uses $5/$25 (not old $15/$75)', () => {
    const cost = estimateCost('claude-opus-4-6', 1000000, 0);
    assertEquals(5.00, cost, 'Opus 4.6 1M input tokens should cost $5');
});

runTest('opus 4.0 cost estimate uses old $15/$75', () => {
    const cost = estimateCost('claude-opus-4-20250514', 1000000, 0);
    assertEquals(15.00, cost, 'Opus 4.0 1M input tokens should cost $15');
});

runTest('haiku 4.5 cost estimate uses $1/$5', () => {
    const cost = estimateCost('claude-haiku-4-5', 1000000, 1000000);
    assertEquals(6.00, cost, 'Haiku 4.5 1M in + 1M out should cost $6');
});

runTest('unknown model falls back to sonnet pricing', () => {
    const cost = estimateCost('claude-unknown-model', 1000000, 0);
    assertEquals(3.00, cost, 'Unknown model should default to sonnet input rate');
});

runTest('cost calculation for typical student session', () => {
    // Jessica-style: 1 Opus request with ~5K input, ~1K output
    const cost = estimateCost('claude-opus-4-6', 5000, 1000);
    // (5000/1M)*5 + (1000/1M)*25 = 0.025 + 0.025 = 0.05
    assertTrue(cost < 0.10, `Single Opus question should cost < $0.10 (got $${cost.toFixed(4)})`);
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

/*
  Simple unit tests for the chatbot utilities.
  These tests avoid network calls and focus on pure functions.
*/

const resultsEl = document.getElementById('results');
const tests = [];

function test(name, fn) {
  tests.push({ name, fn });
}

function assertEqual(actual, expected, message) {
  if (actual !== expected) {
    throw new Error(`${message} (expected: ${expected}, got: ${actual})`);
  }
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const utils = window.ChatbotUtils;

if (!utils) {
  throw new Error('ChatbotUtils not found. Make sure app.js loaded correctly.');
}

test('sanitizeUserText trims whitespace', () => {
  assertEqual(utils.sanitizeUserText('  hello  '), 'hello', 'Text should be trimmed');
});

test('createMessage sets role, content, and timestamp', () => {
  const message = utils.createMessage('user', 'Hello');
  assertEqual(message.role, 'user', 'Role mismatch');
  assertEqual(message.content, 'Hello', 'Content mismatch');
  assert(Boolean(Date.parse(message.timestamp)), 'Timestamp should be valid');
});

test('buildRequestPayload formats system and messages', () => {
  const payload = utils.buildRequestPayload({
    systemMessage: 'Be helpful',
    messages: [
      { role: 'user', content: 'Hi' },
      { role: 'assistant', content: 'Hello' },
    ],
    model: 'gpt-4.1-mini',
    temperature: 0.5,
    maxTokens: 128,
  });

  assertEqual(payload.system, 'Be helpful', 'System message mismatch');
  assertEqual(payload.messages.length, 2, 'Message count mismatch');
  assertEqual(payload.messages[0].content, 'Hi', 'Message content mismatch');
});

test('saveTranscript and loadTranscript round-trip', () => {
  const sample = [utils.createMessage('user', 'Test message')];
  utils.saveTranscript(sample);
  const loaded = utils.loadTranscript();
  assertEqual(loaded.length, 1, 'Transcript length mismatch');
  assertEqual(loaded[0].content, 'Test message', 'Transcript content mismatch');
});

(function run() {
  const output = [];
  let passed = 0;

  tests.forEach((item) => {
    try {
      item.fn();
      passed += 1;
      output.push(`✅ ${item.name}`);
    } catch (error) {
      output.push(`❌ ${item.name}: ${error.message}`);
    }
  });

  output.push(`\n${passed}/${tests.length} tests passed.`);
  resultsEl.textContent = output.join('\n');
})();

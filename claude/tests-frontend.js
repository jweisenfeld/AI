#!/usr/bin/env node
/**
 * Frontend test suite — runs the REAL functions out of index.html.
 *
 *     node tests-frontend.js
 *
 * index.html is one self-contained file with no module system, so each function
 * under test is lifted out of the source and evaluated here with the browser
 * globals it needs stubbed around it. That is deliberately not a copy: a copied
 * function proves nothing about the code the students actually load.
 *
 * Covers the three things that have actually broken in this file:
 *   1. formatMessage()          — code blocks, unclosed fences, indentation
 *   2. rollBackUserTurn()       — conversation integrity after a failed request
 *   3. consumeAssistantStream() — SSE parsing, including chunk-split frames
 */

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(path.join(__dirname, 'index.html'), 'utf8').replace(/\r\n/g, '\n');

/** Lift a function's source out of index.html by its signature. */
function grab(signature) {
  const a = SRC.indexOf(signature);
  if (a < 0) throw new Error('not found in index.html: ' + signature);
  const end = SRC.indexOf('\n        }\n', a);
  if (end < 0) throw new Error('no closing brace for: ' + signature);
  return SRC.slice(a, end + 11);
}

let passed = 0;
let failed = 0;
function check(label, ok, detail) {
  if (ok) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label + (detail ? '  (' + detail + ')' : '')); }
}

// ---------------------------------------------------------------- formatMessage
console.log('\nformatMessage:');

const escapeStub = {
  createElement: () => ({
    set textContent(v) { this._t = v; },
    get innerHTML() {
      return String(this._t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },
  }),
};

const formatMessage = new Function(
  'document', 'window',
  grab('function escapeHtml(text) {') + '\n' + grab('function formatMessage(text) {') +
  '\nreturn formatMessage;'
)(escapeStub, {});

const CLOSED = 'Here you go:\n\n```python\ndef move(self):\n    if self.on_ground:\n        self.vy = -12\n```\n\nTry it!';
const UNCLOSED = 'Below:\n\n```python\n# ====== CONSTANTS ======\nSCREEN_WIDTH = 800\n\nclass Player:\n    def __init__(self):\n        self.x = 50';

const closed = formatMessage(CLOSED);
const unclosed = formatMessage(UNCLOSED);
const two = formatMessage('First:\n\n```js\nlet a = 1;\n```\n\nThen **bold**.\n\n```py\nb = 2\n```');
const inline = formatMessage('## Heading\n\nUse `print()` here.');

check('closed fence renders a code block', closed.includes('<div class="code-block">'));
check('closed fence labels the language', closed.includes('>python'));
check('closed fence is not flagged cut off', !closed.includes('code-cutoff'));
check('code block is not wrapped in a paragraph', !/<p>\s*<div class="code-block"/.test(closed));
check('prose around the block still renders', closed.includes('<p>Here you go:</p>'));
check('unclosed fence still renders as code', unclosed.includes('<div class="code-block">'));
check('unclosed fence preserves indentation', unclosed.includes('        self.x = 50'));
check('unclosed fence produces no stray heading', !unclosed.includes('<h1>'));
check('unclosed fence is flagged cut off', unclosed.includes('code-cutoff'));
check('every block gets a copy button', closed.includes('copyCodeBlock(this)'));
check('two blocks both render', (two.match(/code-block"/g) || []).length === 2);
check('bold between blocks still works', two.includes('<strong>bold</strong>'));
check('inline code is untouched', inline.includes('<code>print()</code>'));
check('headings are untouched', inline.includes('<h2>Heading</h2>'));

// ------------------------------------------------------------ rollBackUserTurn
console.log('\nrollBackUserTurn:');

let inputEl = { value: '' };
const messages = [];
const uiState = { pendingImages: [], rendered: 0 };

const rollBack = new Function(
  'messages', 'state', 'document',
  'const autoResize = () => {};\n' +
  'let pendingImages = state.pendingImages;\n' +
  'const renderImagePreviews = () => { state.rendered++; state.pendingImages = pendingImages; };\n' +
  grab('function rollBackUserTurn(turn, text, images) {') + '\n' +
  'return (t, x, i) => { pendingImages = state.pendingImages; rollBackUserTurn(t, x, i); };'
)(messages, uiState, { getElementById: (id) => (id === 'message-input' ? inputEl : null) });

const turn = { role: 'user', content: 'a' };
messages.push({ role: 'user', content: 'old' }, { role: 'assistant', content: 'reply' }, turn);
inputEl = { value: '' };
rollBack(turn, 'a');
check('removes the failed user turn', messages.length === 2 && !messages.includes(turn));
check('leaves earlier history intact', messages[0].content === 'old' && messages[1].content === 'reply');
check('history still ends on an assistant turn', messages[messages.length - 1].role === 'assistant');

messages.length = 0;
messages.push({ role: 'user', content: 'same' });
rollBack({ role: 'user', content: 'same' }, 'same');
check('does not remove a look-alike turn it did not add', messages.length === 1);

inputEl = { value: '' };
rollBack({ role: 'user', content: 'x' }, 'my long question');
check('restores text to an empty composer', inputEl.value === 'my long question');

inputEl = { value: 'a new thought' };
rollBack({ role: 'user', content: 'x' }, 'my long question');
check('does not overwrite text already typed', inputEl.value === 'a new thought');

uiState.pendingImages = [];
uiState.rendered = 0;
const imgs = [{ preview: 'blob:1' }];
inputEl = { value: '' };
rollBack({ role: 'user', content: [] }, '', imgs);
check('restores attachments to an empty composer',
  uiState.pendingImages === imgs && uiState.rendered === 1);

uiState.pendingImages = [{ preview: 'blob:new' }];
const before = uiState.rendered;
rollBack({ role: 'user', content: [] }, '', imgs);
check('does not overwrite a newly attached image',
  uiState.pendingImages[0].preview === 'blob:new' && uiState.rendered === before);

check('wired into both failure paths',
  (SRC.match(/rollBackUserTurn\(userTurn, text, sentImages\)/g) || []).length >= 2);
check('attachments captured before the composer is cleared',
  SRC.indexOf('const sentImages = pendingImages;') < SRC.indexOf('\n            pendingImages = [];'));

// ------------------------------------------------------- consumeAssistantStream
console.log('\nconsumeAssistantStream:');

/** Build a Response-like object that yields the given byte chunks. */
function fakeResponse(chunks) {
  let i = 0;
  return {
    body: {
      getReader: () => ({
        read: async () => (i < chunks.length
          ? { done: false, value: new TextEncoder().encode(chunks[i++]) }
          : { done: true, value: undefined }),
      }),
    },
  };
}

async function runStream(chunks) {
  const painted = [];
  const el = { innerHTML: '' };
  const box = { scrollHeight: 0, scrollTop: 0, clientHeight: 0 };

  const streamingDiv = { classList: { remove() {}, add() {} }, querySelector: () => el };
  const doc = {
    getElementById: (id) => (id === 'chat-messages' ? box : null),
    createElement: () => ({ className: '', innerHTML: '', appendChild() {}, querySelector: () => el }),
  };

  const consume = new Function(
    'document', 'requestAnimationFrame', 'formatMessage', 'TextDecoder', 'painted', 'streamingDiv',
    'let streamAbort = null;\n' +
    'let streamingEl = streamingDiv;\n' +
    'function beginStreamingMessage() { return streamingDiv; }\n' +
    grab('async function consumeAssistantStream(response, loadingDiv) {') + '\n' +
    'return consumeAssistantStream;'
  )(doc, (cb) => { cb(); }, (t) => { painted.push(t); return t; }, TextDecoder, painted, streamingDiv);

  const result = await consume(fakeResponse(chunks), null);
  return { result, painted };
}

const FRAMES = [
  'event: delta\ndata: {"text":"Hello "}\n\n',
  'event: delta\ndata: {"text":"world"}\n\n',
  'event: done\ndata: {"usage":{"input_tokens":5,"output_tokens":9},"model":"m-1","stop_reason":"end_turn"}\n\n',
];

(async () => {
  let r = await runStream(FRAMES);
  check('assembles text from delta events', r.result.text === 'Hello world', JSON.stringify(r.result.text));
  check('captures usage from the done event', r.result.usage && r.result.usage.output_tokens === 9);
  check('captures the model that answered', r.result.model === 'm-1');
  check('captures stop_reason', r.result.stop_reason === 'end_turn');
  check('not marked aborted on a clean finish', r.result.aborted === false);

  // The whole stream arriving as one chunk, and split mid-frame, must both work.
  r = await runStream([FRAMES.join('')]);
  check('handles every frame arriving in one chunk', r.result.text === 'Hello world');

  const joined = FRAMES.join('');
  const split = [];
  for (let i = 0; i < joined.length; i += 7) split.push(joined.slice(i, i + 7));
  r = await runStream(split);
  check('reassembles frames split across chunk boundaries', r.result.text === 'Hello world',
    JSON.stringify(r.result.text));

  // A mid-stream error must surface, keeping whatever text already arrived.
  r = await runStream([
    'event: delta\ndata: {"text":"partial"}\n\n',
    'event: error\ndata: {"type":"model_timeout","message":"took too long"}\n\n',
  ]);
  check('surfaces a mid-stream error', r.result.error && r.result.error.type === 'model_timeout');
  check('keeps the text that arrived before the error', r.result.text === 'partial');

  // SSE comments (keep-alive padding) must not be treated as data.
  r = await runStream([': keep-alive\n\n', 'event: delta\ndata: {"text":"ok"}\n\n']);
  check('ignores SSE comment lines', r.result.text === 'ok');

  // Malformed JSON should be skipped, not thrown.
  r = await runStream(['event: delta\ndata: {oops\n\n', 'event: delta\ndata: {"text":"fine"}\n\n']);
  check('skips an unparseable frame instead of throwing', r.result.text === 'fine');

  console.log('\n' + '='.repeat(60));
  console.log(`Passed: ${passed}  Failed: ${failed}`);
  console.log('='.repeat(60));
  process.exit(failed ? 1 : 0);
})();

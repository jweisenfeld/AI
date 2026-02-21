/**
 * test-stream.mjs  —  Node.js integration test for gemini3 SSE streaming
 *
 * Uses the EXACT same fetch + ReadableStream + delta-extraction logic as index.html,
 * copy-pasted verbatim so any divergence is caught immediately.
 *
 * Node v22+ has native fetch/ReadableStream — no npm install needed.
 *
 * Usage:
 *   node gemini3/test-stream.mjs
 *   node gemini3/test-stream.mjs https://psd1.net/gemini3   # override base URL
 */

const BASE_URL = process.argv[2] ?? 'https://psd1.net/gemini3';
const PROXY    = `${BASE_URL}/api-proxy.php`;

// ─── Colour helpers ────────────────────────────────────────────────────────────
const G = s => `\x1b[32m${s}\x1b[0m`;  // green
const R = s => `\x1b[31m${s}\x1b[0m`;  // red
const B = s => `\x1b[1m${s}\x1b[0m`;   // bold

let passed = 0, failed = 0;
function pass(label)         { console.log(`  ${G('✓')} ${label}`); passed++; }
function fail(label, detail) { console.log(`  ${R('✗')} ${label}${detail ? ` — ${detail}` : ''}`); failed++; }
function section(title)      { console.log(`\n${B(title)}`); }

// ─── Shared payload ───────────────────────────────────────────────────────────
const BASE_BODY = {
    action:       'chat',
    student_id:   'test_node',
    student_name: 'Node Test',
    system:       'You are a helpful assistant. Be very brief.',
    messages:     [{ role: 'user', parts: [{ text: 'Reply with exactly four words: node test works correctly' }] }],
};

// ─── THE DELTA EXTRACTOR ──────────────────────────────────────────────────────
// ⚠️  THIS MUST STAY IN SYNC WITH index.html's sendMessage() delta logic ⚠️
// When you change the browser code, update this function too.
function extractDelta(parsed) {
    // Format A: our simplified wrapper {"text":"..."}  (if PHP callback fires)
    let delta = parsed.text ?? null;

    // Format B: Gemini raw SSE — iterate parts, skip thoughtSignature blobs
    if (delta === null && Array.isArray(parsed.candidates?.[0]?.content?.parts)) {
        for (const part of parsed.candidates[0].content.parts) {
            if (typeof part.text === 'string' && part.text.length > 0
                    && !part.thoughtSignature) {
                delta = part.text;
                break;
            }
        }
    }
    return delta;  // null if nothing useful in this chunk
}

// ─── THE STREAM READER ────────────────────────────────────────────────────────
// ⚠️  THIS MUST STAY IN SYNC WITH index.html's sendMessage() while-loop ⚠️
async function readStream(response) {
    const reader      = response.body.getReader();
    const decoder     = new TextDecoder();
    let accumulated   = '';
    let partialLine   = '';
    let doneReceived  = false;
    let chunkCount    = 0;

    while (true) {
        const { value, done } = await reader.read();
        if (done) break;

        partialLine += decoder.decode(value, { stream: true });

        const lines = partialLine.split('\n');
        partialLine = lines.pop();   // last element may be incomplete

        for (const line of lines) {
            const trimmed = line.trimEnd();
            if (!trimmed.startsWith('data: ')) continue;

            const payload = trimmed.slice(6);
            if (payload === '[DONE]') { doneReceived = true; break; }

            let parsed;
            try { parsed = JSON.parse(payload); } catch { continue; }

            if (parsed.error) throw new Error(JSON.stringify(parsed.error));

            const delta = extractDelta(parsed);
            if (delta) {
                accumulated += delta;
                chunkCount++;
            }
        }

        if (doneReceived) break;
    }

    return { accumulated, doneReceived, chunkCount };
}

// ─── TEST RUNNER ──────────────────────────────────────────────────────────────
async function testModel(model) {
    section(`Model: ${model}`);

    const body = JSON.stringify({ ...BASE_BODY, model });

    // --- Streaming route ---
    console.log(`  ${PROXY}?stream=1`);
    const streamRes = await fetch(`${PROXY}?stream=1`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
    });

    streamRes.status === 200
        ? pass('HTTP 200')
        : fail('HTTP 200', `got ${streamRes.status}`);

    const ct = streamRes.headers.get('content-type') ?? '';
    ct.includes('text/event-stream')
        ? pass('Content-Type: text/event-stream')
        : fail('Content-Type: text/event-stream', `got: ${ct}`);

    const { accumulated, doneReceived, chunkCount } = await readStream(streamRes);

    doneReceived
        ? pass('[DONE] received')
        : fail('[DONE] received');

    chunkCount > 0
        ? pass(`Non-zero text chunks (${chunkCount})`)
        : fail('Non-zero text chunks', 'no text delta extracted from any chunk');

    accumulated.length > 0
        ? pass(`Non-empty response: "${accumulated.trim()}"`)
        : fail('Non-empty response', 'accumulated string is empty');
}

async function testFallback() {
    section('Non-streaming fallback (no ?stream)');
    console.log(`  ${PROXY}`);

    const body = JSON.stringify({ ...BASE_BODY, model: 'gemini-2.5-flash-lite' });
    const res  = await fetch(PROXY, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
    });

    res.status === 200
        ? pass('HTTP 200')
        : fail('HTTP 200', `got ${res.status}`);

    const ct = res.headers.get('content-type') ?? '';
    ct.includes('application/json')
        ? pass('Content-Type: application/json')
        : fail('Content-Type: application/json', `got: ${ct}`);

    const json = await res.json().catch(() => null);
    json !== null
        ? pass('Valid JSON body')
        : fail('Valid JSON body');

    const text = json?.candidates?.[0]?.content?.parts?.[0]?.text ?? null;
    text && text.length > 0
        ? pass(`Non-empty response: "${text.trim()}"`)
        : fail('Non-empty response', 'could not extract text from candidates');
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
(async () => {
    console.log(B(`\ngemini3 stream test  →  ${BASE_URL}\n`) + '='.repeat(55));

    await testModel('gemini-2.5-flash-lite');
    await testModel('gemini-2.5-flash');
    await testModel('gemini-3-pro-preview');
    await testFallback();

    console.log(`\n${'='.repeat(55)}`);
    console.log(`${G(`${passed} passed`)}  ${failed > 0 ? R(`${failed} failed`) : '0 failed'}\n`);
    process.exit(failed > 0 ? 1 : 0);
})();

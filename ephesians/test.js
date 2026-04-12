// Node.js unit tests for ephesians quiz data and shuffle logic
// Run with: node test.js
'use strict';

const fs = require('fs');
const path = require('path');

const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'data.json'), 'utf8'));

let passed = 0;
let failed = 0;

function ok(label, condition, detail = '') {
  if (condition) {
    console.log(`  PASS  ${label}`);
    passed++;
  } else {
    console.error(`  FAIL  ${label}${detail ? ' — ' + detail : ''}`);
    failed++;
  }
}

// ── Helpers (mirror the browser JS) ─────────────────────────────────────────

function groupShortVerses(raw) {
  const result = [];
  let i = 0;
  while (i < raw.length) {
    const v = raw[i];
    if (v.t.length < 50 && i + 1 < raw.length && raw[i + 1].c === v.c) {
      const next = raw[i + 1];
      result.push({ chapter: v.c, verseStart: v.v, verseEnd: next.v, text: v.t + ' ' + next.t });
      i += 2;
    } else {
      result.push({ chapter: v.c, verseStart: v.v, verseEnd: null, text: v.t });
      i++;
    }
  }
  return result;
}

function shuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

// ── Test 1: data.json — no duplicate verse references ────────────────────────

console.log('\nTest 1: data.json has no duplicate verse references');
{
  const seen = new Set();
  let dup = null;
  for (const v of data) {
    const key = `${v.c}:${v.v}`;
    if (seen.has(key)) { dup = key; break; }
    seen.add(key);
  }
  ok('no duplicate c:v keys', dup === null, dup || '');
}

// ── Test 2: data.json — all required fields present ──────────────────────────

console.log('\nTest 2: every verse has c, v, t fields with correct types');
{
  let bad = null;
  for (const v of data) {
    if (typeof v.c !== 'number' || typeof v.v !== 'number' || typeof v.t !== 'string') {
      bad = JSON.stringify(v); break;
    }
  }
  ok('all fields typed correctly', bad === null, bad || '');
}

// ── Test 3: data.json — chapters 1-6 only, verses sequential ─────────────────

console.log('\nTest 3: chapters are 1–6 only');
{
  const chapters = new Set(data.map(v => v.c));
  ok('only chapters 1–6 present', [...chapters].every(c => c >= 1 && c <= 6));
  ok('all six chapters present', chapters.size === 6);
}

// ── Test 4: groupShortVerses — no duplicates ──────────────────────────────────

console.log('\nTest 4: groupShortVerses produces no duplicate entries');
const grouped = groupShortVerses(data);
{
  const seen = new Set();
  let dup = null;
  for (const v of grouped) {
    const key = `${v.chapter}:${v.verseStart}`;
    if (seen.has(key)) { dup = key; break; }
    seen.add(key);
  }
  ok('no duplicate verseStart keys after grouping', dup === null, dup || '');
  ok('grouped count is less than or equal to raw count', grouped.length <= data.length);
  console.log(`       ${data.length} raw verses → ${grouped.length} quiz entries (${data.length - grouped.length} short verses merged)`);
}

// ── Test 5: groupShortVerses — all source verses accounted for ────────────────

console.log('\nTest 5: all source verses are accounted for in grouped entries');
{
  // Sum of source verses represented: singles count 1, merged pairs count 2
  const represented = grouped.reduce((n, v) => n + (v.verseEnd !== null ? 2 : 1), 0);
  ok('every source verse appears in a grouped entry', represented === data.length,
    `represented=${represented} vs raw=${data.length}`);
}

// ── Test 6: shuffle — correct length, no duplicates (10 trials) ───────────────

console.log('\nTest 6: shuffle produces full deck with no duplicates (10 trials)');
{
  let allOk = true;
  for (let trial = 0; trial < 10; trial++) {
    const deck = shuffle(grouped);
    if (deck.length !== grouped.length) {
      ok(`trial ${trial} correct length`, false, `${deck.length} ≠ ${grouped.length}`);
      allOk = false;
      continue;
    }
    const seen = new Set();
    let dup = null;
    for (const v of deck) {
      const key = `${v.chapter}:${v.verseStart}`;
      if (seen.has(key)) { dup = key; break; }
      seen.add(key);
    }
    if (dup) {
      ok(`trial ${trial} no duplicates`, false, dup);
      allOk = false;
    }
  }
  if (allOk) ok('10 shuffle trials: correct length, zero duplicates each time', true);
}

// ── Test 7: deck exhaustion — simulated full round ────────────────────────────

console.log('\nTest 7: simulated full round sees every entry exactly once');
{
  const deck = shuffle(grouped);
  const seen = new Map(); // key → count
  for (let i = 0; i < deck.length; i++) {
    const key = `${deck[i].chapter}:${deck[i].verseStart}`;
    seen.set(key, (seen.get(key) || 0) + 1);
  }
  const anyOver1 = [...seen.values()].some(n => n > 1);
  ok('every quiz entry appears exactly once per deck', !anyOver1);
  ok('deck length matches grouped verse count', deck.length === grouped.length);
}

// ── Summary ───────────────────────────────────────────────────────────────────

console.log(`\n${'─'.repeat(52)}`);
console.log(`  ${passed} passed, ${failed} failed`);
if (failed > 0) {
  console.error('  Some tests FAILED\n');
  process.exit(1);
} else {
  console.log('  All tests passed\n');
}

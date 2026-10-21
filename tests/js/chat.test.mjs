import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';

globalThis.document = { querySelector: () => null };
// bundled like the app does (its modules import each other without file extensions)
const bundle = await build({ entryPoints: ['resources/js/chat.js'], bundle: true, format: 'esm', platform: 'node', write: false, logLevel: 'silent' });
const { streamChat } = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));

test('the chat request always has a time limit, so a stalled connection cannot leave the chat waiting forever', async () => {
  let seen;
  globalThis.fetch = async (url, init) => {
    seen = init;
    let sent = false;

    return { ok: true, status: 200, body: { getReader: () => ({ read: async () => (sent ? { done: true } : ((sent = true), { done: false, value: new TextEncoder().encode('event: done\ndata: []\n\n') })) }) } };
  };
  await streamChat({ payload: { messages: [] } }, { onText() {} });
  assert.ok(seen.signal instanceof AbortSignal, 'a default timeout signal is used');
  assert.equal(seen.signal.aborted, false);

  const own = new AbortController();
  await streamChat({ payload: { messages: [] }, signal: own.signal }, { onText() {} });
  assert.equal(seen.signal, own.signal, 'a signal given by the caller wins');
});

test('a request that times out rejects instead of hanging', async () => {
  globalThis.fetch = (url, init) => new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(init.signal.reason)));
  const ctl = new AbortController();
  const pending = streamChat({ payload: { messages: [] }, signal: ctl.signal }, { onText() {} });
  ctl.abort(new DOMException('timed out', 'TimeoutError'));
  await assert.rejects(pending, (e) => e.name === 'TimeoutError');
});

test('stopping keeps what has arrived and ends without an error; a timeout still rejects', async () => {
  const enc = new TextEncoder();
  const ctl = new AbortController();
  let got = '';
  globalThis.fetch = async (url, init) => {
    let n = 0;

    return { ok: true, status: 200, body: { getReader: () => ({ read: () => (n++ === 0
      ? Promise.resolve({ done: false, value: enc.encode('event: text\ndata: {"t":"Olá"}\n\n') })
      : new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(init.signal.reason)))) }) } };
  };
  const pending = streamChat({ payload: { messages: [] }, signal: ctl.signal }, { onText: (t) => { got += t; } });
  await new Promise((r) => setTimeout(r, 20));
  ctl.abort('stop');
  const res = await pending;
  assert.equal(got, 'Olá');
  assert.equal(res.stopped, true);
  assert.equal(res.patch, null);

  globalThis.fetch = (url, init) => new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(init.signal.reason)));
  const late = new AbortController();
  const p2 = streamChat({ payload: { messages: [] }, signal: late.signal }, { onText() {} });
  late.abort(new DOMException('timed out', 'TimeoutError'));
  await assert.rejects(p2, (e) => e.name === 'TimeoutError');
});

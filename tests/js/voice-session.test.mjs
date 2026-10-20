import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';

const fire = { instances: [] };
// like Chrome: results are cumulative within one recognition session; a session can end by itself; stop() still delivers last words
class FakeSR {
  constructor() { fire.instances.push(this); this.list = []; }
  start() { this.running = true; }
  stop() { this.running = false; setTimeout(() => { if (this.tail) this.finalize(this.tail); this.onend?.(); }, 5); } // the provisional phrase becomes final (replaced, not added)
  abort() { this.running = false; setTimeout(() => this.onend?.(), 0); }
  push(text, isFinal) { this.list.push(Object.assign([{ transcript: text }], { isFinal })); this.onresult?.({ resultIndex: this.list.length - 1, results: this.list }); }
  sayAlternatives(alts) { this.list = this.list.filter((r) => r.isFinal); this.list.push(Object.assign(alts.map((transcript) => ({ transcript })), { isFinal: false })); this.onresult?.({ resultIndex: this.list.length - 1, results: this.list }); }
  say(text) { this.list = this.list.filter((r) => r.isFinal); this.push(text, false); this.tail = (this.list.filter((r) => r.isFinal).map((r) => r[0].transcript).join('') + ' ' + text).trim(); }
  finalize(text) { this.list = this.list.filter((r) => r.isFinal); this.push(text, true); }
  endByItself() { this.running = false; this.onend?.(); }
  fail(error) { this.onerror?.({ error }); this.onend?.(); }
}
globalThis.window = { SpeechRecognition: FakeSR, matchMedia: () => ({ matches: false }), addEventListener() {}, location: { pathname: '/' } };
const box = { style: {}, scrollHeight: 0, scrollTop: 0, value: '', sel: null, setSelectionRange(a, b) { this.sel = [a, b]; } }; // the composer's textarea
globalThis.document = { getElementById: (id) => (id === 'composer-input' ? box : null), addEventListener() {}, documentElement: { dataset: {} }, querySelector: () => null };
globalThis.localStorage = { getItem: () => null, setItem() {} };
globalThis.history = { pushState() {}, replaceState() {} };

// the real store, bundled on the fly (it imports other modules of the app)
const bundle = await build({ entryPoints: ['resources/js/store.js'], bundle: true, format: 'esm', platform: 'node', write: false, logLevel: 'silent' });
const { createStore } = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));
// Alpine hands out reactive proxies for everything kept in the store, so `store.x !== x` even for the same object.
// The harness behaves the same way: it caught (too late) code that compared a stored session with the original.
const proxies = new WeakMap();
const reactive = (o) => {
  if (proxies.has(o)) return proxies.get(o);
  const p = new Proxy(o, { get(t, k, r) { const v = Reflect.get(t, k, r); return v && typeof v === 'object' ? reactive(v) : v; } });
  proxies.set(o, p);
  return p;
};
const mk = () => { fire.instances.length = 0; const s = reactive(createStore({ user: { name: 'T' }, i18n: { pt: { errors: {} } }, locale: 'pt', icons: {}, conversations: [] })); s.notify = (m) => (s.lastNotice = m); s.focusComposer = () => {}; return s; };
test('the harness reproduces the reactive store (stored objects come back as proxies)', () => {
  const s = mk(); const o = { a: 1 }; s.stash = o;
  assert.notEqual(s.stash, o);
});
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const last = () => fire.instances.at(-1);
const t = (name, fn) => test(name, fn);

t('dictated words come out cleaned up (spoken punctuation, capital)', async () => {
  const s = mk(); s.startVoice(); last().say('olá vírgula tudo bem ponto de interrogação');
  assert.equal(s.composer.text, 'Olá, tudo bem?');
});
t('the box follows the dictation: after a long text the end is in view and the caret is at the end', async () => {
  const s = mk(); s.startVoice();
  last().say('uma frase muito comprida que enche a caixa e obriga a ver o fim');
  box.value = s.composer.text; box.scrollHeight = 480; box.scrollTop = 0; // what the browser would have after writing the text
  await wait(20);
  assert.equal(box.scrollTop, 480, 'scrolled to the end');
  assert.deepEqual(box.sel, [box.value.length, box.value.length], 'caret at the end');
  assert.match(box.style.height, /^\d+px$/, 'resized to its content');
});
t('the recognizer guesses several ways and the one with the words of the app is used', async () => {
  const s = mk(); s.catalog = { byProject: { DEV: { assignees: [{ value: 'paulo.alves', label: 'Paulo Alves' }] } } };
  s.startVoice(); last().sayAlternatives(['lista os inches do paulo', 'lista os issues do paulo']);
  assert.equal(s.composer.text, 'Lista os issues do Paulo');
});
t('the recognizer is asked for several alternatives', async () => {
  const s = mk(); s.startVoice(); assert.ok(last().maxAlternatives >= 3);
});
t('what was typed before is kept and the sentence continues in lower case', async () => {
  const s = mk(); s.composer.text = 'já escrevi isto'; s.startVoice(); last().say('e mais isto');
  assert.equal(s.composer.text, 'já escrevi isto e mais isto');
  const s2 = mk(); s2.composer.text = 'Terminei.'; s2.startVoice(); last().say('próxima frase');
  assert.equal(s2.composer.text, 'Terminei. Próxima frase');
});
t('the browser ends a session after a pause: the app starts another and nothing is lost', async () => {
  const s = mk(); s.startVoice(); const first = last();
  first.finalize('primeira frase ponto final'); first.endByItself();
  await wait(220);
  assert.equal(fire.instances.length, 2, 'a new recognition session must have started');
  assert.equal(s.voice.listening, true);
  last().say('segunda frase');
  assert.equal(s.composer.text, 'Primeira frase. Segunda frase');
});
t('a provisional phrase left when a session ends is kept', async () => {
  const s = mk(); s.startVoice(); const first = last(); first.say('palavras soltas'); first.endByItself(); await wait(220);
  last().say('mais uma');
  assert.equal(s.composer.text, 'Palavras soltas mais uma');
});
t('issue ids said aloud use the projects of the user', async () => {
  const s = mk(); s.projects = [{ key: 'DEV' }, { key: '2010043' }]; s.startVoice(); last().say('altera o dev doze e o 2010043 traço 1989');
  assert.equal(s.composer.text, 'Altera o DEV-12 e o 2010043-1989');
});
t('sending while talking: box stays empty, no new session, late words ignored', async () => {
  const s = mk(); s.startVoice(); const rec = last(); rec.say('manda isto');
  s.stopVoice(true); s.clearComposer(false); rec.finalize(' palavras tardias'); await wait(220);
  assert.equal(s.composer.text, ''); assert.equal(s.voice.listening, false); assert.equal(fire.instances.length, 1, 'must not restart');
});
t('microphone button: last words delivered, and it does not restart', async () => {
  const s = mk(); s.startVoice(); last().say('quase pronto'); s.toggleVoice(); await wait(220);
  assert.equal(s.composer.text, 'Quase pronto'); assert.equal(s.voice.listening, false); assert.equal(fire.instances.length, 1);
});
t('permission denied stops for good and says why', async () => {
  const s = mk(); s.startVoice(); last().fail('not-allowed'); await wait(220);
  assert.equal(s.voice.listening, false); assert.equal(fire.instances.length, 1); assert.match(String(s.lastNotice), /voice_denied/);
});
t('no connection is reported as such', async () => {
  const s = mk(); s.startVoice(); last().fail('network'); await wait(220);
  assert.equal(s.voice.listening, false); assert.match(String(s.lastNotice), /voice_network/);
});
t('silence (no-speech) just starts the next session', async () => {
  const s = mk(); s.startVoice(); last().onerror({ error: 'no-speech' }); last().onend(); await wait(220);
  assert.equal(s.voice.listening, true); assert.equal(fire.instances.length, 2);
});
t('a recognizer that ends at once, again and again, is given up instead of looping', async () => {
  const s = mk(); s.startVoice();
  for (let i = 0; i < 12 && s.voice.listening; i++) { last().endByItself(); await wait(160); }
  assert.equal(s.voice.listening, false); assert.ok(fire.instances.length <= 8, `restarted ${fire.instances.length} times`);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';

// ---- browser mocks -------------------------------------------------------------------------------------------------------
const calls = [];
let routes = [];
globalThis.fetch = async (url, init = {}) => {
  const method = init.method ?? 'GET';
  const body = init.body instanceof FormData ? Object.fromEntries(init.body.entries()) : init.body ? JSON.parse(init.body) : undefined;
  calls.push({ method, url: String(url), body });
  const hit = routes.find((r) => r.method === method && (typeof r.match === 'string' ? String(url).includes(r.match) : r.match.test(String(url))));
  const out = hit ? await (typeof hit.reply === 'function' ? hit.reply(String(url), body) : hit.reply) : { status: 404, json: { message: 'not found' } };

  if (out.sse) { // a streamed answer (Server-Sent Events)
    let sent = false;

    return { status: 200, ok: true, body: { getReader: () => ({ read: async () => (sent ? { done: true } : ((sent = true), { done: false, value: new TextEncoder().encode(out.sse) })) }) } };
  }

  return { status: out.status ?? 200, ok: (out.status ?? 200) < 400, json: async () => out.json ?? null };
};
globalThis.window = { matchMedia: () => ({ matches: false }), addEventListener() {}, location: { pathname: '/' } };
globalThis.document = { getElementById: () => null, addEventListener() {}, documentElement: { dataset: {} }, querySelector: () => null };
const memory = {};
globalThis.localStorage = { getItem: (k) => memory[k] ?? null, setItem: (k, v) => { memory[k] = String(v); }, removeItem: (k) => { delete memory[k]; } };
globalThis.history = { pushState() {}, replaceState() {} };

const bundle = await build({ entryPoints: ['resources/js/store.js'], bundle: true, format: 'esm', platform: 'node', write: false, logLevel: 'silent' });
const { createStore } = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));

// Alpine hands out reactive proxies for what the store keeps; behave the same
const proxies = new WeakMap();
// (Sets and Maps are left alone: Alpine's proxies handle them natively, this simple one does not)
const reactive = (o) => {
  if (proxies.has(o)) return proxies.get(o);
  const p = new Proxy(o, { get(t, k, r) { const v = Reflect.get(t, k, r); return v && typeof v === 'object' && !(v instanceof Set) && !(v instanceof Map) ? reactive(v) : v; } });
  proxies.set(o, p);
  return p;
};
const mk = (extra = {}) => {
  calls.length = 0; routes = []; for (const k of Object.keys(memory)) delete memory[k];
  const s = reactive(createStore({ user: { name: 'T' }, i18n: { pt: { context: { not_in_team: 'NOT_IN_TEAM :project' } } }, locale: 'pt', icons: {}, conversations: [] }));
  s.notices = []; s.notify = (m) => s.notices.push(m); s.focusComposer = () => {}; s.scrollBottom = () => {};
  s.projects = [{ key: 'DEV' }, { key: '2010043' }];
  s.activeId = 1; s.conversations = [{ id: 1, title: 'x' }]; s.trackerUrl = 'https://yt';
  Object.assign(s, extra);
  return s;
};
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const issue = (id, summary = 'Resumo ' + id, state = 'Open') => ({ id, summary, state, project: id.split('-')[0], url: `https://yt/${id}` });
const on = (method, match, reply) => routes.push({ method, match, reply });

// ---- attaching an issue to a message: like a file ------------------------------------------------------------------------------
test('an issue is attached to the message being written, once, with its title and state', () => {
  const s = mk();
  s.attachIssue(issue('DEV-7', 'Botão falha', 'Open')); s.attachIssue(issue('DEV-7', 'Botão falha', 'Open'));
  assert.deepEqual(s.attachedIssues, [{ key: 'DEV-7', summary: 'Botão falha', state: 'Open', url: 'https://yt/DEV-7' }]);
  s.removeIssue('DEV-7'); assert.deepEqual(s.attachedIssues, []);
});
test('a handful of issues at most, like the server allows', () => {
  const s = mk();
  for (let n = 1; n <= 8; n++) s.attachIssue(issue(`DEV-${n}`));
  assert.equal(s.attachedIssues.length, 5); assert.equal(s.notices.length, 3, 'one notice for each one refused');
});
test('an issue known only by its key (typed in the box, or the one just created) is read and attached', async () => {
  const s = mk(); on('GET', '/api/youtrack/issues/DEV-9', { json: issue('DEV-9', 'Nove') });
  await s.attachIssueByKey('DEV-9');
  assert.deepEqual(s.attachedIssues.map((i) => i.key), ['DEV-9']);
  on('GET', '/api/youtrack/issues/DEV-404', { status: 404, json: { message: 'não existe' } });
  await s.attachIssueByKey('DEV-404'); assert.equal(s.attachedIssues.length, 1); assert.match(String(s.notices.at(-1)), /não existe/);
});
test('an issue alone is enough to send a message (like a file alone)', () => {
  const s = mk(); assert.equal(s.canSend, false);
  s.attachIssue(issue('DEV-1')); assert.equal(s.canSend, true);
});
test('every issue attached is remembered for next time (latest first, no repeats, eight at most)', () => {
  const s = mk();
  for (let n = 1; n <= 10; n++) s.attachIssue(issue(`DEV-${n % 4 + n}`)); s.removeIssue('x');
  for (let n = 20; n < 30; n++) { s.attachedIssues = []; s.attachIssue(issue(`DEV-${n}`)); }
  s.attachedIssues = []; s.attachIssue(issue('DEV-25'));
  const saved = JSON.parse(localStorage.getItem('im.contexts'));
  assert.equal(saved.length, 8); assert.equal(saved[0].key, 'DEV-25'); assert.equal(saved.filter((c) => c.key === 'DEV-25').length, 1);
});

// ---- an issue key typed in the box ----------------------------------------------------------------------------------------
test('a key typed in the box is offered for attaching (any case); look-alikes of keys are not', () => {
  const s = mk();
  s.composer.text = 'olha o dev-12 por favor'; assert.equal(s.mentionedKey, 'DEV-12');
  s.composer.text = 'abre 2010043-1989 já'; assert.equal(s.mentionedKey, '2010043-1989');
  s.composer.text = 'devolver 12 itens, UTF-8, editdev-3'; assert.equal(s.mentionedKey, null);
  s.composer.text = ''; assert.equal(s.mentionedKey, null);
});
test('the offer skips issues already attached and the ones the user dismissed; accepting it attaches the issue', async () => {
  const s = mk(); s.attachIssue(issue('DEV-12'));
  s.composer.text = 'dev-12 e dev-13';
  assert.equal(s.mentionedKey, 'DEV-13', 'DEV-12 is attached already');
  on('GET', '/api/youtrack/issues/DEV-13', { json: issue('DEV-13', 'Treze') });
  await s.attachMentioned();
  assert.deepEqual(s.attachedIssues.map((i) => i.key), ['DEV-12', 'DEV-13']); assert.equal(s.mentionedKey, null);
  s.composer.text = 'e o dev-14'; s.dismissMentioned(); assert.equal(s.mentionedKey, null);
});

// ---- recents --------------------------------------------------------------------------------------------------------------
test('recent issues: what this chat carried (newest first), then the ones used lately in this browser; never one already attached; six at most', () => {
  const s = mk();
  s.messages = [
    { id: 1, kind: 'creation-result', role: 'assistant', meta: { key: 'DEV-1', summary: 'um', url: 'u1' } },
    { id: 2, kind: 'text', role: 'user', meta: { contexts: [{ key: 'DEV-4', summary: 'quatro' }, { key: 'DEV-5', summary: 'cinco' }] } },
    { id: 3, kind: 'creation-result', role: 'assistant', meta: { key: 'DEV-2', summary: 'dois', url: 'u2' } },
    { id: 4, kind: 'text', role: 'user', meta: { context: { key: 'DEV-2', summary: 'dois, de novo' } } }, // an older message, with the single-issue shape
    { id: 5, kind: 'text', role: 'user', meta: {} },
  ];
  s.attachIssue(issue('DEV-9'));
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-9', summary: 'o atual', url: 'u9' }, { key: 'DEV-3', summary: 'tres', url: 'u3' }, ...[21, 22, 23, 24].map((n) => ({ key: `DEV-${n}`, summary: 'x', url: 'u' }))]));
  s.loadRecents();
  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-2', 'DEV-4', 'DEV-5', 'DEV-1', 'DEV-3', 'DEV-21'].slice(0, 6));
  assert.equal(s.contextRecents[0].summary, 'dois, de novo');
  assert.equal(s.contextRecents[1].url, 'https://yt/issue/DEV-4', 'a message keeps only the key: the link is rebuilt');
});

// ---- the quick search -----------------------------------------------------------------------------------------------------
test('typing a whole key looks that issue up directly', async () => {
  const s = mk(); s.openContextPicker();
  on('GET', '/api/youtrack/issues/DEV-12', { json: issue('DEV-12') });
  s.contextPicker.q = 'dev-12'; s.searchContext(); await wait(380);
  assert.deepEqual(s.contextItems.map((i) => i.id), ['DEV-12']);
  assert.ok(calls.some((c) => c.url.includes('/api/youtrack/issues/DEV-12')), 'direct lookup (key in upper case)');
  assert.ok(!calls.some((c) => c.url.includes('per_page')), 'no text search needed');
});
test('a key that is not found, and any other text, are searched as text (25 results)', async () => {
  const s = mk(); s.openContextPicker();
  on('GET', '/api/youtrack/issues/DEV-99', { status: 404, json: { message: 'no' } });
  on('GET', /\/api\/youtrack\/issues\?/, { json: { issues: [issue('DEV-5', 'quiosque')] } });
  s.contextPicker.q = 'DEV-99'; s.searchContext(); await wait(380);
  assert.deepEqual(s.contextItems.map((i) => i.id), ['DEV-5']);
  s.contextPicker.q = 'quiosque ecrã'; s.searchContext(); await wait(380);
  const search = calls.filter((c) => c.url.includes('per_page')).at(-1).url;
  assert.match(decodeURIComponent(search.replace(/\+/g, ' ')), /q=quiosque ecrã/); assert.match(search, /per_page=25/);
});
test('the first letter searches at once, and typing fast does not send one search per letter', async () => {
  const s = mk(); s.openContextPicker();
  on('GET', /\/api\/youtrack\/issues\?/, { json: { issues: [] } });
  s.contextPicker.q = 'q'; s.searchContext(); await wait(30);
  assert.equal(calls.filter((c) => c.url.includes('per_page')).length, 1, 'the first one did not wait for a pause');
  for (const q of ['qu', 'qui', 'quio']) { s.contextPicker.q = q; s.searchContext(); await wait(20); }
  await wait(300);
  const sent = calls.filter((c) => c.url.includes('per_page'));
  assert.ok(sent.length <= 3, 'the letters typed in a burst were grouped');
  assert.match(sent.at(-1).url, /q=quio/);
});
test('a slow answer to an old search never replaces the answer to the newer one', async () => {
  const s = mk(); s.openContextPicker();
  on('GET', /\/api\/youtrack\/issues\?/, async (url) => { const slow = url.includes('q=lento'); await wait(slow ? 300 : 10); return { json: { issues: [issue(slow ? 'DEV-1' : 'DEV-2')] } }; });
  s.contextPicker.q = 'lento'; s.searchContext(); await wait(300);   // its request is now in flight
  s.contextPicker.q = 'rapido'; s.searchContext(); await wait(700);
  assert.deepEqual(s.contextItems.map((i) => i.id), ['DEV-2']);
  assert.equal(s.contextPicker.loading, false);
});
test('emptying the box shows the recent issues again, a failing search shows its error', async () => {
  const s = mk(); s.openContextPicker();
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-3', summary: 'tres', url: 'u' }])); s.loadRecents();
  s.contextPicker.q = ''; s.searchContext();
  assert.deepEqual(s.contextItems.map((i) => i.id), ['DEV-3']);
  on('GET', /\/api\/youtrack\/issues\?/, { status: 502, json: { message: 'YouTrack em baixo' } });
  s.contextPicker.q = 'algo'; s.searchContext(); await wait(380);
  assert.equal(s.contextPicker.error, 'YouTrack em baixo'); assert.deepEqual(s.contextItems, []);
});
test('arrows move through the results and Enter picks the highlighted one (which attaches it to the message and closes the search)', async () => {
  const s = mk(); s.openContextPicker();
  s.contextPicker.q = 'x'; s.contextPicker.results = [issue('DEV-1'), issue('DEV-2'), issue('DEV-3')];
  s.moveContext(1); s.moveContext(1); assert.equal(s.contextPicker.active, 2);
  s.moveContext(1); assert.equal(s.contextPicker.active, 0, 'wraps around');
  s.moveContext(-1); assert.equal(s.contextPicker.active, 2);
  await s.pickActiveContext();
  assert.deepEqual(s.attachedIssues.map((i) => i.key), ['DEV-3']); assert.equal(s.contextPicker.open, false);
});


// ---- what goes to Claude and to the server, per message ----------------------------------------------------------------------
test('every message Claude sees carries the issues that came with it, the new one too; an assistant message never does', async () => {
  const s = mk();
  s.messages = [
    { id: 1, role: 'user', kind: 'text', body: 'altera a prioridade', meta: { contexts: [{ key: 'DEV-7', summary: 'S' }, { key: 'DEV-8', summary: 'T' }] }, attachments: [] },
    { id: 2, role: 'assistant', kind: 'text', body: 'feito', meta: {}, attachments: [] },
    { id: 3, role: 'user', kind: 'text', body: '', meta: { contexts: [{ key: 'DEV-9', summary: 'U' }] }, attachments: [] },
    { id: 4, role: 'user', kind: 'text', body: 'e uma pergunta solta', meta: {}, attachments: [] },
  ];
  assert.deepEqual(s.historyForApi(), [
    { role: 'user', content: '[Contexto: DEV-7, DEV-8] altera a prioridade' }, { role: 'assistant', content: 'feito' },
    { role: 'user', content: '[Contexto: DEV-9]' }, { role: 'user', content: 'e uma pergunta solta' },
  ]);

  s.attachIssue(issue('DEV-12', 'Quiosque')); s.catalog = { projects: [], byProject: {} };
  on('POST', '/api/chat', { sse: 'event: text\ndata: {"t":"ok"}\n\nevent: done\ndata: []\n\n' });
  on('POST', '/api/conversations/1/turn', (u, body) => ({ json: { conversation: { id: 1, title: 'x' }, messages: [{ id: 10, role: 'user', kind: 'text', body: 'fecha', meta: { contexts: JSON.parse(body.contexts) }, attachments: [] }, { id: 11, role: 'assistant', kind: 'text', body: 'ok', meta: {}, attachments: [] }], draft: null } }));
  s.composer.text = 'fecha o issue'; // sent from the box, as when the user presses Enter
  await s.send();
  const chat = JSON.parse(calls.find((c) => c.url.endsWith('/api/chat')).body.payload); const turn = calls.find((c) => c.url.endsWith('/turn'));
  assert.deepEqual(chat.contexts, [{ key: 'DEV-12' }]);
  assert.deepEqual(chat.messages.at(-1), { role: 'user', content: '[Contexto: DEV-12] fecha o issue' });
  assert.deepEqual(JSON.parse(turn.body.contexts), [{ key: 'DEV-12', summary: 'Quiosque' }]);
  assert.equal(s.messages.find((m) => m.id === 10).meta.contexts[0].key, 'DEV-12');
  assert.deepEqual(s.attachedIssues, [], 'gone after sending, like a sent file: the next message has no issue unless one is attached again');
});
test('a message with no issue sends none: no tag, nothing stored', async () => {
  const s = mk(); s.catalog = { projects: [], byProject: {} };
  on('POST', '/api/chat', { sse: 'event: text\ndata: {"t":"ok"}\n\nevent: done\ndata: []\n\n' });
  on('POST', '/api/conversations/1/turn', { json: { conversation: { id: 1, title: 'x' }, messages: [{ id: 10, role: 'user', kind: 'text', body: 'olá', meta: {}, attachments: [] }, { id: 11, role: 'assistant', kind: 'text', body: 'ok', meta: {}, attachments: [] }], draft: null } });
  await s.send('olá');
  const sent = JSON.parse(calls.find((c) => c.url.endsWith('/api/chat')).body.payload);
  assert.deepEqual(sent.contexts, []); assert.equal(sent.messages.at(-1).content, 'olá');
  assert.equal(calls.find((c) => c.url.endsWith('/turn')).body.contexts, undefined);
});
test('an issue alone is sent as a message of its own (no text)', async () => {
  const s = mk(); s.catalog = { projects: [], byProject: {} }; s.attachIssue(issue('DEV-3', 'Três'));
  on('POST', '/api/chat', { sse: 'event: text\ndata: {"t":"ok"}\n\nevent: done\ndata: []\n\n' });
  on('POST', '/api/conversations/1/turn', { json: { conversation: { id: 1, title: 'x' }, messages: [{ id: 10, role: 'user', kind: 'text', body: '', meta: { contexts: [{ key: 'DEV-3', summary: 'Três' }] }, attachments: [] }, { id: 11, role: 'assistant', kind: 'text', body: 'ok', meta: {}, attachments: [] }], draft: null } });
  await s.send();
  const sent = JSON.parse(calls.find((c) => c.url.endsWith('/api/chat')).body.payload);
  assert.match(sent.messages.at(-1).content, /^\[Contexto: DEV-3\] \S/, 'a placeholder text follows the tag');
  assert.equal(calls.find((c) => c.url.endsWith('/turn')).body.body, '');
});
test('if sending fails the issues come back to the box, with the text', async () => {
  const s = mk(); s.catalog = { projects: [], byProject: {} }; s.attachIssue(issue('DEV-3', 'Três'));
  on('POST', '/api/chat', { status: 500, json: { message: 'falhou' } });
  s.composer.text = 'fecha este';
  await s.send();
  assert.equal(s.composer.text, 'fecha este'); assert.deepEqual(s.attachedIssues.map((i) => i.key), ['DEV-3']);
});
test('starting a new chat clears what was being written, issues included', () => {
  const s = mk(); s.attachIssue(issue('DEV-3')); s.composer.text = 'a meio';
  s.newChat();
  assert.deepEqual(s.attachedIssues, []); assert.equal(s.composer.text, '');
});
test('the paperclip menu closes when the issue search opens', () => {
  const s = mk(); s.attachMenu = true; s.openContextPicker();
  assert.equal(s.attachMenu, false); assert.equal(s.contextPicker.open, true);
});

// ---- deleted issues must not be offered as recent ----------------------------------------------------------------------------
test('recent issues that were deleted in YouTrack (or are no longer visible) disappear from the list and from the memory', async () => {
  const s = mk();
  s.messages = [{ id: 1, kind: 'creation-result', role: 'assistant', meta: { key: 'DEV-3', summary: 'apagado, criado neste chat', url: 'u3' } }];
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-1', summary: 'apagado', url: 'u1' }, { key: 'DEV-2', summary: 'continua a existir', url: 'u2' }]));
  on('GET', '/api/youtrack/issues/DEV-1', { status: 404, json: { message: 'não existe' } });
  on('GET', '/api/youtrack/issues/DEV-3', { status: 404, json: { message: 'não existe' } });
  on('GET', '/api/youtrack/issues/DEV-2', { json: issue('DEV-2', 'continua a existir') });
  s.loadRecents();
  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-3', 'DEV-1', 'DEV-2'], 'before asking YouTrack they are all listed');

  s.openContextPicker();
  assert.deepEqual(s.contextRecents, [], 'opened: nothing is offered until YouTrack has answered, so a deleted issue never flashes');
  await wait(60);

  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-2'], 'the deleted ones are gone, also the one this chat created');
  assert.deepEqual(JSON.parse(localStorage.getItem('im.contexts')).map((c) => c.key), ['DEV-2'], 'and forgotten for next time');
});
test('a recent issue is kept when YouTrack merely fails, and its title is refreshed when it was renamed', async () => {
  const s = mk();
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-1', summary: 'título antigo', url: 'u1' }, { key: 'DEV-2', summary: 'x', url: 'u2' }]));
  on('GET', '/api/youtrack/issues/DEV-1', { json: issue('DEV-1', 'título novo') });
  on('GET', '/api/youtrack/issues/DEV-2', { status: 502, json: { message: 'YouTrack em baixo' } });

  s.openContextPicker(); await wait(60);

  assert.deepEqual(s.contextRecents.map((i) => i.id).sort(), ['DEV-1', 'DEV-2'], 'a failure is not a deletion');
  assert.equal(JSON.parse(localStorage.getItem('im.contexts')).find((c) => c.key === 'DEV-1').summary, 'título novo');
});
test('reopening the search soon after does not ask YouTrack about the same recent issues again', async () => {
  const s = mk();
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-8', summary: 'x', url: 'u' }]));
  on('GET', '/api/youtrack/issues/DEV-8', { json: issue('DEV-8') });
  s.openContextPicker(); await wait(60); s.closeContextPicker(); s.openContextPicker(); await wait(60);
  assert.equal(calls.filter((c) => c.url.endsWith('/DEV-8')).length, 1);
});
test('an issue that is deleted is not offered even if it only shows up in an earlier message of this chat', async () => {
  const s = mk();
  s.messages = [{ id: 1, kind: 'text', role: 'user', body: 'x', meta: { contexts: [{ key: 'DEV-5', summary: 'apagado' }] }, attachments: [] }];
  on('GET', '/api/youtrack/issues/DEV-5', { status: 404, json: { message: 'não existe' } });
  s.openContextPicker(); await wait(60);
  assert.deepEqual(s.contextRecents, []);
});

test('the list of recent issues follows what is remembered: a change shows without reopening anything', () => {
  const s = mk(); s.loadRecents();
  assert.deepEqual(s.contextRecents, []);
  s.attachIssue(issue('DEV-4', 'Quatro')); s.removeIssue('DEV-4');                        // remembered by attaching
  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-4']);
  s.forgetRecent('DEV-4');                                                                  // and forgotten when it turns out to be deleted
  assert.deepEqual(s.contextRecents, []); assert.equal(localStorage.getItem('im.contexts'), null);
});
test('opening the search reads the browser memory again (what another tab remembered shows up)', async () => {
  const s = mk(); s.loadRecents();
  localStorage.setItem('im.contexts', JSON.stringify([{ key: 'DEV-6', summary: 'seis', url: 'u' }]));
  on('GET', '/api/youtrack/issues/DEV-6', { json: issue('DEV-6', 'seis') });
  s.openContextPicker(); await wait(60);
  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-6']);
});

test('checking the recent issues keeps their order, whatever order YouTrack answers in, and forgets every deleted one', async () => {
  const s = mk();
  const keys = ['DEV-61', 'DEV-62', 'DEV-63', 'DEV-64', 'DEV-65', 'DEV-66']; // keys used by no other test (an issue confirmed lately is not asked about again)
  localStorage.setItem('im.contexts', JSON.stringify(keys.map((key) => ({ key, summary: key, url: 'u' }))));
  const gone = ['DEV-62', 'DEV-65'];
  for (const key of keys) {
    // answered out of order: the later ones first
    on('GET', `/api/youtrack/issues/${key}`, async () => { await wait(70 - Number(key.slice(-1)) * 10); return gone.includes(key) ? { status: 404, json: { message: 'no' } } : { json: issue(key, key) }; });
  }
  s.openContextPicker(); await wait(150);
  assert.deepEqual(s.contextRecents.map((i) => i.id), ['DEV-61', 'DEV-63', 'DEV-64', 'DEV-66'], 'same order as before, minus the deleted');
  assert.deepEqual([...s.goneIssues].sort(), ['DEV-62', 'DEV-65']);
  assert.deepEqual(JSON.parse(localStorage.getItem('im.contexts')).map((c) => c.key), ['DEV-61', 'DEV-63', 'DEV-64', 'DEV-66']);
});

// ---- "my issues": ticking issues and starting a chat about them ---------------------------------------------------------------
test('ticked issues of "my issues" go to a new chat as context, and the message written in the bar is sent with them', () => {
  const s = mk(); s.page = 'mine';
  const sent = [];
  s.send = () => sent.push({ text: s.composer.text, keys: s.attachedIssues.map((i) => i.key) });
  s.toggleMineSelect(issue('DEV-1')); s.toggleMineSelect(issue('DEV-2')); s.toggleMineSelect(issue('DEV-1'));
  assert.deepEqual(s.mineSelected.map((i) => i.id), ['DEV-2'], 'ticking again unticks');
  s.toggleMineSelect(issue('DEV-3'));
  s.mineText = '  resume estes  ';
  s.startChatFromMine();
  assert.equal(s.page, 'chat');
  assert.deepEqual(sent, [{ text: 'resume estes', keys: ['DEV-2', 'DEV-3'] }]);
  assert.deepEqual(s.mineSelected, []); assert.equal(s.mineText, '');

  s.page = 'mine'; s.toggleMineSelect(issue('DEV-7')); s.startChatFromMine();
  assert.equal(sent.length, 1, 'without a message nothing is sent: the issues wait in the box');
  assert.deepEqual(s.attachedIssues.map((i) => i.key), ['DEV-7']);
});
test('no more than the issues one message can carry can be ticked', () => {
  const s = mk();
  for (let n = 1; n <= 7; n++) s.toggleMineSelect(issue(`DEV-${n}`));
  assert.equal(s.mineSelected.length, 5);
});
test('the tick boxes belong to the selection mode: leaving it drops what was ticked', () => {
  const s = mk();
  s.toggleMineSelectMode(); s.toggleMineSelect(issue('DEV-1'));
  assert.equal(s.mineSelectMode, true); assert.equal(s.mineSelected.length, 1);
  s.toggleMineSelectMode();
  assert.equal(s.mineSelectMode, false); assert.deepEqual(s.mineSelected, []);
});

test('the range under a list reads "0-48 (123 issues)", then "48-96 (123 issues)", and the last page ends where the issues end', () => {
  const s = mk(); s.t = (k, p = {}) => (k === 'mine.total' ? `${p.n} issues` : k);
  assert.equal(s.rangeLabel(1, 48, 48, 123), '0-48 (123 issues)');
  assert.equal(s.rangeLabel(2, 48, 48, 123), '48-96 (123 issues)');
  assert.equal(s.rangeLabel(3, 48, 27, 123), '96-123 (123 issues)');
  assert.equal(s.rangeLabel(1, 12, 12, null), '0-12');
});

test('each project has a count of its own: the part of ITS issues on the page, then how many it has', () => {
  const s = mk(); s.t = (k, p = {}) => (k === 'mine.total' ? `${p.n} issues` : k);
  const mkIssue = (id, project) => ({ id, project, project_name: project });
  const list = [mkIssue('A-1', 'A'), mkIssue('A-2', 'A'), mkIssue('B-1', 'B')];
  assert.deepEqual(s.groupByProject(list, { A: 50, B: 3 }, { A: [48, 50], B: [0, 1] }).map((g) => g.count), ['48-50 (50 issues)', '0-1 (3 issues)']);
  assert.deepEqual(s.groupByProject(list, { A: 50, B: 3 }, { B: [0, 1] }).map((g) => g.count), ['(50 issues)', '0-1 (3 issues)'], 'without a range only the total is shown');
  assert.deepEqual(s.groupByProject(list).map((g) => g.count), ['', '']);
});

test('a quiet search that overtakes a visible one still ends the loading (the list must not stay dimmed)', async () => {
  const s = mk();
  on('GET', /\/api\/youtrack\/issues\?/, async () => { await wait(20); return { json: { issues: [issue('DEV-1')], has_more: false, total: 1, page: 1 } }; });
  const visible = s.searchIssues(1);
  const quiet = s.searchIssues(1, true);
  await Promise.all([visible, quiet]);
  assert.equal(s.issuesLoading, false);
  assert.equal(s.issueResults.length, 1);
});

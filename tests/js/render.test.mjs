import { renderText } from '../../resources/js/render.js';
import test from 'node:test';
import assert from 'node:assert/strict';
const o = { issueUrl: 'https://yt.test/', keys: ['DEV', '2010043'] };
const t = (name, fn) => test(name, fn);

t('bullets, bold, nested list', () => {
  const h = renderText('Tens 3:\n- **DEV-1** — Botão (Aberto)\n- DEV-2 — Login\n  - sub um\n  - sub dois\n- fim', o);
  assert.match(h, /<ul><li><strong><a [^>]*href="https:\/\/yt.test\/issue\/DEV-1"[^>]*class="issue-key"[^>]*>DEV-1<\/a><\/strong> — Botão \(Aberto\)<\/li>/);
  assert.match(h, /<li><a [^>]*>DEV-2<\/a> — Login<ul><li>sub um<\/li><li>sub dois<\/li><\/ul><\/li><li>fim<\/li><\/ul>/);
});
t('numbered list and numeric project keys', () => {
  const h = renderText('1. 2010043-1989 primeiro\n2. segundo', o);
  assert.match(h, /^<ol><li><a [^>]*href="[^"]*2010043-1989"[^>]*>2010043-1989<\/a> primeiro<\/li><li>segundo<\/li><\/ol>$/);
});
t('keys in backticks are linked, other code stays code, unknown keys untouched', () => {
  const h = renderText('Ver `DEV-7` e `npm run x`, UTF-8 e COVID-19', o);
  assert.match(h, /<a [^>]*>DEV-7<\/a>/); assert.match(h, /<code>npm run x<\/code>/);
  assert.ok(!h.includes('issue/UTF') && !h.includes('issue/COVID'));
});
t('a key is not linked inside a markdown link or code block', () => {
  const h = renderText('[ver DEV-1](https://x.test/a)\n```\nDEV-2\n```', o);
  assert.equal((h.match(/class="issue-key"/g) || []).length, 0);
  assert.match(h, /<pre[^>]*><code>DEV-2<\/code><\/pre>/);
});
t('table', () => {
  const h = renderText('| Chave | Estado |\n|---|---|\n| DEV-1 | Aberto |\n| DEV-2 | Feito |', o);
  assert.match(h, /<div class="table-wrap"><table><thead><tr><th>Chave<\/th><th>Estado<\/th><\/tr><\/thead><tbody><tr><td><a [^>]*>DEV-1<\/a><\/td><td>Aberto<\/td><\/tr>/);
});
t('xss is escaped everywhere', () => {
  const h = renderText('- <img src=x onerror=alert(1)>\n| <script>x</script> | b |\n|---|---|\n| `<b>` | [a](javascript:alert(1)) |\n# <i>t</i>', o);
  assert.ok(!/<img|<script|<i>|<b>/.test(h), h);
  assert.ok(!h.includes('href="javascript'), h);
});
t('without keys or url nothing is linked and old behaviour holds', () => {
  const h = renderText('# Título\n\ntexto **forte** e _itálico_\n- a\n- b\n\n---\n> citação');
  assert.match(h, /<h2>Título<\/h2><p>texto <strong>forte<\/strong> e <em>itálico<\/em><\/p><ul><li>a<\/li><li>b<\/li><\/ul><hr><blockquote>citação<\/blockquote>/);
});
t('list followed by paragraph closes the list', () => {
  assert.equal(renderText('- a\ntexto'), '<ul><li>a</li></ul><p>texto</p>');
});

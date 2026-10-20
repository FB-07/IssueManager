const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const escRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Inline markdown (code, bold, italic, links) on already-escaped text. Code spans and links are set aside first so that nothing
 * inside them is rewritten, and issue keys of the known projects (`DEV-12`) become links to the issue.
 */
function inline(raw, opts) {
    const stash = [];
    const keep = (html) => `\u0000${stash.push(html) - 1}\u0000`;
    const keyRe = opts.keys?.length && opts.issueUrl
        ? new RegExp(`(^|[^\\w-])((?:${[...opts.keys].sort((a, b) => b.length - a.length).map(escRe).join('|')})-\\d+)(?![\\w-])`, 'g')
        : null;

    let s = esc(raw)
        .replace(/`([^`]+)`/g, (m, c) => keep(keyRe && new RegExp(`^(?:${opts.keys.map(escRe).join('|')})-\\d+$`).test(c) ? issueLink(c, opts) : `<code>${c}</code>`))
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (m, t, u) => keep(`<a href="${u}" target="_blank" rel="noopener noreferrer" class="text-accent underline">${t}</a>`));

    s = s
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[\s(])_([^_]+)_(?=[\s).,:;!?]|$)/g, '$1<em>$2</em>');
    if (keyRe) s = s.replace(keyRe, (m, pre, key) => pre + keep(issueLink(key, opts)));

    // restore the set-aside pieces (a link text may itself hold a code span, hence the repeat)
    for (let n = 0; n < 3 && s.includes('\u0000'); n++) s = s.replace(/\u0000(\d+)\u0000/g, (m, i) => stash[Number(i)]);

    return s;
}

const issueLink = (key, opts) =>
    `<a href="${esc(opts.issueUrl.replace(/\/$/, ''))}/issue/${encodeURIComponent(key)}" target="_blank" rel="noopener noreferrer" class="issue-key" title="${esc(key)}">${esc(key)}</a>`;

const cells = (line) =>
    line
        .trim()
        .replace(/^\||\|$/g, '')
        .split('|')
        .map((c) => c.trim());

const isTableSep = (line) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line) && line.includes('|') && line.includes('-');

const BULLET = /^(\s*)([-*•])\s+(.*)$/;
const NUMBER = /^(\s*)(\d+)[.)]\s+(.*)$/;

/**
 * Small XSS-safe markdown subset for chat text and issue previews: headings, nested bullet and numbered lists, tables, code
 * blocks, quotes, rules, bold/italic, links and (with `opts`) links on issue keys.
 *
 * @param {string} src
 * @param {{issueUrl?: string, keys?: string[]}} [opts] issueUrl: YouTrack base URL; keys: project keys whose issue ids get linked
 */
export function renderText(src, opts = {}) {
    const lines = String(src ?? '').split('\n');
    const inl = (t) => inline(t, opts);
    let html = '';
    const lists = []; // open lists, outermost first: { type: 'ul' | 'ol', liOpen: boolean }
    let fence = null; // array of code lines while inside ```

    const closeLists = (to = 0) => {
        while (lists.length > to) {
            const l = lists.pop();
            html += `${l.liOpen ? '</li>' : ''}</${l.type}>`;
        }
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        if (fence !== null) {
            if (/^\s*```/.test(line)) {
                html += `<pre class="my-2 max-h-80 overflow-auto rounded-lg border border-line bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed"><code>${esc(fence.join('\n'))}</code></pre>`;
                fence = null;
            } else fence.push(line);
            continue;
        }
        if (/^\s*```/.test(line)) {
            closeLists();
            fence = [];
            continue;
        }

        const item = line.match(BULLET) ?? line.match(NUMBER);
        if (item) {
            const type = BULLET.test(line) ? 'ul' : 'ol';
            const depth = Math.min(Math.floor(item[1].replace(/\t/g, '  ').length / 2), 3);
            closeLists(depth + 1);
            if (lists.length === depth + 1 && lists[depth].type !== type) closeLists(depth);
            while (lists.length < depth + 1) {
                const t = lists.length === depth ? type : 'ul';
                html += t === 'ol' ? '<ol>' : '<ul>';
                lists.push({ type: t, liOpen: false });
            }
            const l = lists[depth];
            html += `${l.liOpen ? '</li>' : ''}<li>${inl(item[3])}`;
            l.liOpen = true;
            continue;
        }

        // a table: header row, separator row, then body rows
        if (line.trim().startsWith('|') && i + 1 < lines.length && isTableSep(lines[i + 1])) {
            closeLists();
            const head = cells(line);
            let rows = '';
            i += 2;
            while (i < lines.length && lines[i].trim().startsWith('|')) {
                rows += `<tr>${cells(lines[i]).map((c) => `<td>${inl(c)}</td>`).join('')}</tr>`;
                i++;
            }
            i--;
            html += `<div class="table-wrap"><table><thead><tr>${head.map((c) => `<th>${inl(c)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
            continue;
        }

        closeLists();
        if (/^#{1,4}\s+/.test(line)) html += `<h2>${inl(line.replace(/^#{1,4}\s+/, ''))}</h2>`;
        else if (/^\s*([-*_])\1{2,}\s*$/.test(line)) html += '<hr>';
        else if (/^\s*>\s?/.test(line)) html += `<blockquote>${inl(line.replace(/^\s*>\s?/, ''))}</blockquote>`;
        else if (line.trim() !== '') html += `<p>${inl(line)}</p>`;
    }
    if (fence !== null) html += `<pre class="my-2 overflow-auto rounded-lg border border-line bg-surface-2 p-3 font-mono text-[12.5px]"><code>${esc(fence.join('\n'))}</code></pre>`;
    closeLists();

    return html;
}

export const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

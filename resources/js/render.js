const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const inline = (s) =>
    esc(s)
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[\s(])_([^_]+)_(?=[\s).,:;!?]|$)/g, '$1<em>$2</em>')
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="text-accent underline">$1</a>');

/** Small XSS-safe markdown subset for chat text and issue previews (headings, lists, code blocks, bold, links). */
export function renderText(src) {
    const lines = String(src ?? '').split('\n');
    let html = '';
    let list = null; // 'ul' | 'ol'
    let fence = null; // array of code lines while inside ```
    const close = () => {
        if (list) {
            html += `</${list}>`;
            list = null;
        }
    };
    for (const line of lines) {
        if (fence !== null) {
            if (/^\s*```/.test(line)) {
                html += `<pre class="my-2 max-h-80 overflow-auto rounded-lg border border-line bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed"><code>${esc(fence.join('\n'))}</code></pre>`;
                fence = null;
            } else fence.push(line);
            continue;
        }
        if (/^\s*```/.test(line)) {
            close();
            fence = [];
        } else if (/^\s*[-*]\s+/.test(line)) {
            if (list !== 'ul') {
                close();
                html += '<ul>';
                list = 'ul';
            }
            html += `<li>${inline(line.replace(/^\s*[-*]\s+/, ''))}</li>`;
        } else if (/^\s*\d+[.)]\s+/.test(line)) {
            if (list !== 'ol') {
                close();
                html += '<ol class="my-2 list-decimal space-y-1 pl-5">';
                list = 'ol';
            }
            html += `<li>${inline(line.replace(/^\s*\d+[.)]\s+/, ''))}</li>`;
        } else if (/^#{1,4}\s+/.test(line)) {
            close();
            html += `<h2>${inline(line.replace(/^#{1,4}\s+/, ''))}</h2>`;
        } else if (line.trim() === '') {
            close();
        } else {
            close();
            html += `<p>${inline(line)}</p>`;
        }
    }
    if (fence !== null) html += `<pre class="my-2 overflow-auto rounded-lg border border-line bg-surface-2 p-3 font-mono text-[12.5px]"><code>${esc(fence.join('\n'))}</code></pre>`;
    close();

    return html;
}

export const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

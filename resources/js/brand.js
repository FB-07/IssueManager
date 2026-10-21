// Puts the YouTrack mark in front of every "YouTrack" in the interface's text (labels, buttons, messages, notices).
// Not touched: what the user types, the chat's own answers, and attributes (title, placeholder) that cannot hold an image.
const WORD = /YouTrack/g;
const SKIP = 'script,style,textarea,input,select,option,[contenteditable],.prose-msg,.yt-word,[data-no-brand]';
const SRC = '/images/youtrack-mark.png';

const decorate = (text) => {
    const parent = text.parentElement;
    if (!parent || parent.closest(SKIP) || !text.nodeValue.includes('YouTrack')) return;

    const frag = document.createDocumentFragment();
    let last = 0;
    for (const m of text.nodeValue.matchAll(WORD)) {
        frag.append(text.nodeValue.slice(last, m.index));
        const span = document.createElement('span');
        span.className = 'yt-word';
        const img = document.createElement('img');
        img.src = SRC;
        img.alt = '';
        img.setAttribute('aria-hidden', 'true');
        img.draggable = false;
        span.append(img, 'YouTrack');
        frag.append(span);
        last = m.index + m[0].length;
    }
    frag.append(text.nodeValue.slice(last));
    text.replaceWith(frag);
};

const scan = (root) => {
    if (root.nodeType === Node.TEXT_NODE) return decorate(root);
    if (root.nodeType !== Node.ELEMENT_NODE || root.closest(SKIP)) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const found = [];
    while (walker.nextNode()) if (walker.currentNode.nodeValue.includes('YouTrack')) found.push(walker.currentNode);
    found.forEach(decorate);
};

let started = false;

export function initBrand() {
    if (started) return;
    started = true;
    let queue = new Set();
    let scheduled = false;
    const flush = () => {
        scheduled = false;
        const nodes = [...queue];
        queue = new Set();
        nodes.forEach((n) => n.isConnected && scan(n));
    };
    new MutationObserver((records) => {
        for (const r of records) {
            if (r.type === 'characterData') queue.add(r.target);
            else r.addedNodes.forEach((n) => queue.add(n));
        }
        if (!scheduled && queue.size) {
            scheduled = true;
            requestAnimationFrame(flush);
        }
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
    scan(document.body);
}

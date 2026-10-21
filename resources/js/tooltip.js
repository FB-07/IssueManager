// Custom tooltips: every element with a `title` gets the app's own bubble instead of the browser's.
// The title is moved to data-tip while the bubble is up (so the native one never shows) and put back afterwards.
const DELAY = 800;
let bubble;
let timer;
let current = null;

const MAC = /Mac|iPhone|iPad/.test(navigator.platform);
const KEYS = { alt: MAC ? '⌥' : 'Alt', mod: MAC ? '⌘' : 'Ctrl', shift: MAC ? '⇧' : 'Shift' };

const ensure = () => {
    if (!bubble) {
        bubble = document.createElement('div');
        bubble.className = 'tooltip';
        bubble.setAttribute('role', 'tooltip');
        document.body.append(bubble);
    }

    return bubble;
};

const hide = () => {
    clearTimeout(timer);
    if (bubble) bubble.classList.remove('is-on');
    if (current) {
        if (current.dataset.tip && !current.hasAttribute('title')) current.setAttribute('title', current.dataset.tip);
        delete current.dataset.tip;
    }
    current = null;
};

const place = (el) => {
    const b = ensure();
    b.textContent = el.dataset.tip;
    // data-shortcut="alt+N": the keys are shown next to the text
    (el.dataset.shortcut ?? '').split('+').filter(Boolean).forEach((k) => {
        const kbd = document.createElement('kbd');
        kbd.textContent = KEYS[k] ?? k;
        b.append(kbd);
    });
    b.style.left = '0px';
    b.style.top = '0px';
    const r = el.getBoundingClientRect();
    const w = b.offsetWidth;
    const h = b.offsetHeight;
    const gap = 8;
    const below = r.bottom + gap + h <= window.innerHeight - 4;
    b.style.top = `${below ? r.bottom + gap : Math.max(4, r.top - gap - h)}px`;
    b.style.left = `${Math.min(Math.max(r.left + r.width / 2 - w / 2, 8), window.innerWidth - w - 8)}px`;
    b.classList.add('is-on');
};

const show = (el) => {
    if (el === current) return;
    hide();
    const title = el.getAttribute('title');
    if (title) {
        el.dataset.tip = title;
        el.removeAttribute('title');
    }
    if (!el.dataset.tip) return;
    current = el;
    timer = setTimeout(() => current === el && place(el), Number(el.dataset.tipDelay ?? DELAY));
};

export function initTooltips() {
    const target = (e) => (e.target instanceof Element ? e.target.closest('[title],[data-tip]') : null);

    document.addEventListener('mouseover', (e) => {
        const el = target(e);
        if (el) show(el);
    });
    document.addEventListener('mouseout', (e) => {
        if (current && !(e.relatedTarget instanceof Node && current.contains(e.relatedTarget))) hide();
    });
    document.addEventListener('focusin', (e) => {
        const el = target(e);
        if (el && e.target.matches(':focus-visible')) show(el);
    });
    document.addEventListener('focusout', hide);
    ['mousedown', 'keydown', 'touchstart', 'wheel'].forEach((ev) => document.addEventListener(ev, hide, { passive: true, capture: true }));
    window.addEventListener('scroll', hide, true);
    window.addEventListener('blur', hide);
}

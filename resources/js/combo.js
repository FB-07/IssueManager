/**
 * Searchable dropdown (combobox) used instead of the native <select>.
 *
 * cfg: {
 *   options: () => (string | {value, label, hint?})[],   // reactive getter
 *   value: () => current value,
 *   pick: (value) => void,                                // called with the chosen value ('' for the empty option)
 *   emptyLabel?: () => string,                            // adds a first "none / all" option that selects ''
 *   placeholder?: () => string,
 *   searchable?: boolean | null,                          // default: true (every dropdown can be searched; pass false for tiny lists)
 *   disabled?: () => boolean,
 *   onOpen?: () => void,
 *   placeholderSearch?: () => string,
 *   emptyText?: () => string,
 * }
 */
const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
let seq = 0;

export function combo(cfg) {
    return {
        open: false,
        query: '',
        active: 0,
        panelStyle: '',
        uid: `cb${++seq}`,

        get options() {
            const base = (cfg.options() ?? []).map((o) => (typeof o === 'object' && o !== null ? o : { value: o, label: String(o) }));

            return cfg.emptyLabel ? [{ value: '', label: cfg.emptyLabel(), empty: true }, ...base] : base;
        },
        get filtered() {
            const q = norm(this.query).trim();
            if (!q) return this.options;

            return this.options.filter((o) => !o.empty && norm(`${o.label} ${o.value} ${o.hint ?? ''}`).includes(q));
        },
        get current() {
            const v = cfg.value();
            const found = this.options.find((o) => String(o.value) === String(v ?? ''));
            if (found) return found;

            return v !== null && v !== undefined && v !== '' ? { value: v, label: String(v) } : null;
        },
        get isEmpty() {
            const v = cfg.value();

            return v === null || v === undefined || v === '';
        },
        get placeholder() {
            return cfg.placeholder ? cfg.placeholder() : '';
        },
        get label() {
            return this.current && !(this.isEmpty && !this.current.empty) ? this.current.label : this.placeholder;
        },
        get searchable() {
            return cfg.searchable ?? true;
        },
        get disabled() {
            return cfg.disabled ? cfg.disabled() : false;
        },
        isSelected(o) {
            return String(o.value) === String(cfg.value() ?? '');
        },

        toggle() {
            this.open ? this.close() : this.show();
        },
        show(prefill = '') {
            if (this.disabled || this.open) return;
            cfg.onOpen?.();
            this.query = prefill;
            this.place();
            this.open = true;
            const i = this.filtered.findIndex((o) => this.isSelected(o));
            this.active = prefill ? 0 : Math.max(0, i);
            this.$nextTick(() => {
                this.place();
                (this.searchable ? this.$refs.search : this.$refs.list)?.focus({ preventScroll: true });
                this.reveal();
            });
        },
        close(refocus = false) {
            this.open = false;
            if (refocus) this.$nextTick(() => this.$refs.trigger?.focus());
        },
        pick(o) {
            if (!o) return;
            cfg.pick(o.value);
            this.close(true);
        },
        /** Fixed position computed from the trigger, so the list is never clipped by a scrolling modal. */
        place() {
            const t = this.$refs.trigger;
            if (!t) return;
            const r = t.getBoundingClientRect();
            const width = Math.max(r.width, 200);
            const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
            const below = window.innerHeight - r.bottom - 12;
            const above = r.top - 12;
            const up = below < 240 && above > below;
            const maxHeight = Math.max(160, Math.min(320, up ? above : below));
            this.panelStyle = `left:${left}px;width:${width}px;max-height:${maxHeight}px;${up ? `bottom:${window.innerHeight - r.top + 4}px` : `top:${r.bottom + 4}px`}`;
        },
        onWindowDown(e) {
            if (!this.open) return;
            if (this.$refs.panel?.contains(e.target) || this.$refs.trigger?.contains(e.target)) return;
            this.close();
        },
        onViewportChange(e) {
            if (this.open && !this.$refs.panel?.contains(e.target)) this.place();
        },
        move(delta) {
            const n = this.filtered.length;
            if (!n) return;
            this.active = (this.active + delta + n) % n;
            this.reveal();
        },
        reveal() {
            this.$nextTick(() => document.getElementById(`${this.uid}-${this.active}`)?.scrollIntoView({ block: 'nearest' }));
        },
        onKey(e) {
            const k = e.key;
            if (!this.open) {
                if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(k)) {
                    e.preventDefault();
                    this.show();
                } else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && this.searchable) {
                    e.preventDefault();
                    this.show(k);
                }

                return;
            }
            if (k === 'ArrowDown') (e.preventDefault(), this.move(1));
            else if (k === 'ArrowUp') (e.preventDefault(), this.move(-1));
            else if (k === 'Home') (e.preventDefault(), (this.active = 0), this.reveal());
            else if (k === 'End') (e.preventDefault(), (this.active = Math.max(0, this.filtered.length - 1)), this.reveal());
            else if (k === 'Enter' || (k === ' ' && !this.searchable)) (e.preventDefault(), this.pick(this.filtered[this.active]));
            else if (k === 'Escape') (e.preventDefault(), e.stopPropagation(), this.close(true));
            else if (k === 'Tab') this.close();
        },
    };
}

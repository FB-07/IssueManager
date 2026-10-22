// Issues attached to a message, quick search and recent ones.
import { api } from '../api';
import { MAX_ISSUES } from './shared';

let contextTimer;
let contextSearchSeq = 0;
let lastContextFire = 0;
const recentChecks = new Map();
const ISSUE_KEY = /^[A-Za-z0-9_.]+-\d+$/;

export function contextSlice() {
    return {
        pendingRecents: [],
        contextPicker: { open: false, q: '', results: [], loading: false, error: '', active: 0 }, // quick search for the context issue
        attachedIssues: [], // the paperclip menu (upload a file / attach an issue)
        contextPicker: { open: false, q: '', results: [], loading: false, error: '', active: 0 }, // quick search for an issue to attach
        dismissedKeys: [], // issue keys typed in the box that the user did not want to attach
        goneIssues: [], // recent issues found to be deleted (or no longer visible): never offered again
        recentList: [],
        /** Opens the quick search for the context issue (the full issue browser stays one click away). */
        openContextPicker() {
            this.attachMenu = false;
            this.loadRecents();
            Object.assign(this.contextPicker, { open: true, q: '', results: [], loading: false, error: '', active: 0 });
            setTimeout(() => document.getElementById('context-search')?.focus(), 30);
            this.verifyRecents(); // an issue is offered only once YouTrack has confirmed it still exists (no flash of a deleted one)
        },
        closeContextPicker() {
            this.contextPicker.open = false;
            clearTimeout(contextTimer);
            contextSearchSeq++;
        },
        /** The full list with filters (the old picker), for when a quick search is not enough. */
        openIssuePicker() {
            const q = this.contextPicker.q.trim(); // what was typed in the quick search carries over to the filters
            this.closeContextPicker();
            if (q) this.issueFilters.q = q;

            return this.openIssues(true, q !== '');
        },
        /** Every recent issue we know of, newest first: what this chat carried, then the ones used lately in this browser. */
        recentCandidates() {
            const seen = new Set();
            const out = [];
            const add = (id, summary, url) => {
                if (id && !seen.has(id) && out.length < 12) {
                    seen.add(id);
                    out.push({ id, summary: summary ?? '', url });
                }
            };
            [...this.messages].reverse().forEach((m) => {
                if (m.kind === 'creation-result' && m.meta?.key) add(m.meta.key, m.meta.summary, m.meta.url);
                else if (m.role === 'user') this.issuesOf(m).forEach((i) => add(i.key, i.summary, this.issueUrl(i.key)));
            });
            this.recentContexts().forEach((c) => add(c.key, c.summary, c.url));

            return out;
        },
        /** Issues to offer before typing anything: the recent ones, minus those attached already and those that no longer exist. */
        get contextRecents() {
            const attached = new Set(this.attachedIssues.map((i) => i.key));

            return this.recentCandidates().filter((c) => !attached.has(c.id) && !this.goneIssues.includes(c.id) && !this.pendingRecents.includes(c.id)).slice(0, 6);
        },
        /**
         * The recent issues come from what this chat carried and from this browser's memory, so some may have been deleted in
         * YouTrack since (or the user may have lost access): ask which still exist, hide the others for good and refresh the titles.
         */
        async verifyRecents() {
            const stale = this.recentCandidates().map((c) => c.id).filter((key) => !(recentChecks.get(key) > Date.now() - 60000));
            this.pendingRecents = [...stale];
            await Promise.all(stale.map(async (key) => {
                try {
                    const issue = await api('GET', `/api/youtrack/issues/${encodeURIComponent(key)}`);
                    recentChecks.set(key, Date.now());
                    // a renamed issue shows its new title (in place: confirming an issue must not move it in the list)
                    if (this.recentList.some((c) => c.key === key && c.summary !== issue.summary)) this.saveRecents(this.recentList.map((c) => (c.key === key ? { ...c, summary: issue.summary, url: issue.url ?? c.url } : c)));
                } catch (e) {
                    if (e.status === 404) this.forgetRecent(key); // deleted, or not visible to this user any more
                    // any other failure (YouTrack down, offline): keep it, it may well exist
                } finally {
                    this.pendingRecents = this.pendingRecents.filter((k) => k !== key);
                }
            }));
        },
        forgetRecent(key) {
            if (!this.goneIssues.includes(key)) this.goneIssues.push(key);
            this.saveRecents(this.recentList.filter((c) => c.key !== key));
        },
        get contextItems() {
            return this.contextPicker.q.trim() ? this.contextPicker.results : this.contextRecents;
        },
        /** Search as you type (after a short pause). A whole key (DEV-12) is looked up directly; anything else is a text search. */
        searchContext() {
            const p = this.contextPicker;
            const q = p.q.trim();
            clearTimeout(contextTimer);
            const seq = ++contextSearchSeq;
            p.active = 0;
            p.error = '';
            if (!q) {
                p.results = [];
                p.loading = false;

                return;
            }
            p.loading = true;
            // the first letter searches at once; while typing fast the following ones are grouped a little (the last one always wins)
            const wait = Date.now() - lastContextFire > 200 ? 0 : 120;
            contextTimer = setTimeout(async () => {
                lastContextFire = Date.now();
                try {
                    let results = [];
                    if (ISSUE_KEY.test(q)) {
                        try {
                            results = [await api('GET', `/api/youtrack/issues/${encodeURIComponent(q.toUpperCase())}`)];
                        } catch (_) {
                            results = []; // not a key after all: search it as text
                        }
                    }
                    if (!results.length) results = (await api('GET', '/api/youtrack/issues?' + new URLSearchParams({ q, per_page: 25 }))).issues ?? [];
                    if (seq === contextSearchSeq) p.results = results;
                } catch (e) {
                    if (seq === contextSearchSeq) {
                        p.results = [];
                        p.error = e.message;
                    }
                } finally {
                    if (seq === contextSearchSeq) p.loading = false;
                }
            }, wait);
        },
        moveContext(delta) {
            const n = this.contextItems.length;
            if (n) this.contextPicker.active = (this.contextPicker.active + delta + n) % n;
        },
        pickActiveContext() {
            const issue = this.contextItems[this.contextPicker.active];
            if (issue) return this.pickContextIssue(issue);
        },
        pickContextIssue(issue) {
            this.closeContextPicker();
            this.attachIssue(issue);
        },
        get mentionedKey() {
            const keys = this.voiceKeys();
            const text = this.composer.text;
            if (!keys.length || !text) return null;
            const re = new RegExp(`(^|[^\\w-])((?:${keys.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})-\\d+)(?![\\w-])`, 'gi');
            const found = [...text.matchAll(re)].map((m) => m[2].toUpperCase());

            return found.find((k) => !this.attachedIssues.some((i) => i.key === k) && !this.dismissedKeys.includes(k)) ?? null;
        },
        async attachMentioned() {
            const key = this.mentionedKey;
            if (key) await this.attachIssueByKey(key);
        },
        dismissMentioned() {
            const key = this.mentionedKey;
            if (key) this.dismissedKeys = [...this.dismissedKeys, key];
        },
        /** From the full issue browser (opened as a picker): the chosen issue is attached to the message. */
        pickIssue(issue) {
            this.issuesOpen = false;
            this.attachIssue(issue);
        },
        /** Attaches an issue to the message being written, like a file (a search result, a recent one or one typed in the box). */
        attachIssue(issue) {
            const key = issue.id ?? issue.key;
            if (!key || this.attachedIssues.some((i) => i.key === key)) return;
            if (this.attachedIssues.length >= MAX_ISSUES) return this.notify(this.t('errors.too_many_issues', { max: MAX_ISSUES }), 'error');
            this.attachedIssues.push({ key, summary: issue.summary ?? '', state: issue.state ?? null, url: issue.url ?? this.issueUrl(key) });
            this.rememberContext({ key, summary: issue.summary ?? '', url: issue.url ?? this.issueUrl(key) });
        },
        removeIssue(key) {
            this.attachedIssues = this.attachedIssues.filter((i) => i.key !== key);
        },
        /** Attaches an issue known only by its key (typed in the box, or the one just created). */
        async attachIssueByKey(key) {
            try {
                this.attachIssue(await api('GET', `/api/youtrack/issues/${encodeURIComponent(key)}`));
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        /** The last issues attached, in this browser, for the quick search to offer before anything is typed. */
        recentContexts() {
            return this.recentList;
        },
        readRecents() {
            try {
                return JSON.parse(localStorage.getItem('im.contexts') || '[]').filter((c) => c && typeof c.key === 'string');
            } catch (e) {
                return [];
            }
        },
        /** Reads the memory of the browser again (also what another tab remembered). Done at start and whenever the search opens. */
        loadRecents() {
            this.recentList = this.readRecents();
        },
        saveRecents(list) {
            this.recentList = list;
            try {
                list.length ? localStorage.setItem('im.contexts', JSON.stringify(list)) : localStorage.removeItem('im.contexts');
            } catch (e) {}
        },
        rememberContext(f) {
            this.saveRecents([{ key: f.key, summary: f.summary, url: f.url }, ...this.recentList.filter((c) => c.key !== f.key)].slice(0, 8));
        },    };
}

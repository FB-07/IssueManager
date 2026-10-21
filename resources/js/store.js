import { api } from './api';
import { renderText, formatSize } from './render';
import { streamChat } from './chat';
import { polishDictation, endsSentence, vocabularyWords, pickAlternative } from './voice';

const REQUIRED = ['summary', 'description', 'project', 'type', 'priority'];
const BLOCKED_EXT = ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'dll', 'jar', 'apk', 'dmg', 'app', 'pif', 'vbs'];
const MAX_FILES = 10;
const QUOTE_MAX = 1500;
const quoteBlock = (t) => t.split('\n').map((l) => `> ${l}`.trimEnd()).join('\n');
const MAX_BYTES = 25 * 1024 * 1024;

let similarTimer;
let batchSimilarTimer;
let stallTimer;
let elapsedTimer;
let answerAbort = null; // aborts the answer being streamed (the stop button)
let contextTimer;
let contextSearchSeq = 0;
let issueTimer;
let lastContextFire = 0;
let issueSearchSeq = 0;
let mineTimer;
let mineSeq = 0;
// when each recent issue was last confirmed to still exist (so reopening the search does not ask YouTrack again right away)
const recentChecks = new Map();
const ISSUE_KEY = /^[A-Za-z0-9_.]+-\d+$/;
const MAX_ISSUES = 5; // issues that can go with one message (as the server allows)
// The dictation in progress. Kept out of the (reactive) store on purpose: the store hands out proxies, so `store.session !== session`
// would always be true and every recognizer event would be taken for a stale one.
let voiceSession = null;
const batchSaveTimers = new Map();
const batchScopes = new Map();
const EMPTY_META = { types: [], priorities: [], subsystems: [], sprints: [], assignees: [], custom: [] };

export function createStore(boot) {
    let saveTimer = null;
    let uid = 0;

    return {
        // ---- boot data -------------------------------------------------
        user: boot.user,
        theme: boot.theme,
        locale: boot.locale,
        locales: boot.locales,
        i18n: boot.i18n,
        icons: boot.icons,
        demo: boot.demo,
        trackerUrl: boot.trackerUrl,
        conversations: boot.conversations ?? [],

        // ---- ui state --------------------------------------------------
        sidebarCollapsed: false,
        sidebarOpen: false,
        settingsOpen: false,
        shortcutsOpen: false,
        altLabel: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌥' : 'Alt', // how the Alt key is written in shortcut hints
        profileMenu: false,
        confirmOpen: false,
        issuesOpen: false,
        page: boot.page ?? 'chat', // 'chat'; 'mine': the user's own issues (/issues); 'all': the existing issues (/issues/all)
        editFromMine: false, // the edit window was opened from that page: closing it returns there
        mineScope: 'assigned',
        mineStatus: 'open',
        mineQ: '',
        mineFilters: { project: '', type: '', priority: '', state: '' },
        minePerPage: 12,
        mineSelectMode: false, // the tick boxes are only there while the user is selecting
        mineProjectTotals: {},
        mineProjectRanges: {},
        issueProjectTotals: {},
        issueProjectRanges: {},
        mineSelected: [], // issues ticked on the page (up to the number that can go with one message)
        mineText: '', // the message written in the bar of the selection
        mineResults: [],
        mineLoading: false,
        minePage: 1,
        mineHasMore: false,
        mineTotal: null,
        mineSummary: null,
        toast: null,
        dragging: false,
        renamingId: null,
        renameValue: '',
        deletingId: null,
        selectMode: false,
        historyMenu: false, // the ⋯ menu above the conversations
        historySearching: false, // the title search box is open
        historyQuery: '',
        selectedIds: [],
        confirmBulk: false,
        bulkDeleting: false,

        // ---- conversation state ----------------------------------------
        activeId: null,
        messages: [],
        draft: null,
        similarIssues: [],
        pendingRecents: [], // recent issues YouTrack is still being asked about: not offered until it answers
        similarKey: null,
        dupAck: false,
        loadingConversation: false,
        processing: false,
        queue: [],
        streaming: false,
        elapsed: 0, // seconds since the message was sent, while the answer is being prepared
        stalled: false, // the answer has started but nothing new arrives (Claude is looking something up): dots show under the text
        draftEnabled: true,
        catalog: null,
        assigneeDeclined: new Set(),
        creating: false,
        creatingKind: 'create',
        advancedOpen: false,
        cardTab: 'edit',
        simulateError: false,
        pending: new Set(),
        batches: {},
        projects: null,
        metaByProject: {},

        // ---- composer ----------------------------------------------------
        composer: { text: '', files: [], quote: '' }, // quote: a passage of an earlier answer this message replies to
        selection: null, // the passage of an answer the user has selected: { text, x, y } (offers "reply")
        // dictation through the browser's speech recognition (Chrome/Edge/Safari); hidden where unsupported
        voice: { supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition), listening: false },

        // ---- issues browser ---------------------------------------------
        issueFilters: { q: '', project: '', type: '', priority: '', state: '', assignee: '' },
        issuePage: 1,
        issuePerPage: 12,
        issueTotal: null,
        issueResults: [],
        issuesLoading: false,
        issueHasMore: false,
        issueEdit: null,
        contextPicker: { open: false, q: '', results: [], loading: false, error: '', active: 0 }, // quick search for the context issue
        attachedIssues: [], // issues attached to the message being written, like its files: [{ key, summary, state, url }]
        attachMenu: false, // the paperclip menu (upload a file / attach an issue)
        contextPicker: { open: false, q: '', results: [], loading: false, error: '', active: 0 }, // quick search for an issue to attach
        dismissedKeys: [], // issue keys typed in the box that the user did not want to attach
        goneIssues: [], // recent issues found to be deleted (or no longer visible): never offered again
        recentList: [], // the issues attached lately in this browser ({ key, summary, url }); a copy of localStorage kept here so the screen follows it
        issuePickMode: false, // the issues modal is choosing the chat's context issue instead of editing
        issueMeta: null,
        issueSaving: false,

        // ================================================================
        init() {
            try {
                this.sidebarCollapsed = localStorage.getItem('im.sidebar') === '1';
            } catch (e) {}
            document.documentElement.lang = this.locale;
            this.loadRecents();
            window.addEventListener('keydown', (e) => e.key === 'Escape' && this.selectMode && !this.confirmBulk && this.cancelSelect());
            window.addEventListener('keydown', (e) => this.onShortcut(e));
            window.addEventListener('popstate', () => this.routeFromLocation());
            document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && this.flushBatches());
            window.addEventListener('pagehide', () => this.flushBatches());
            window.addEventListener('dragover', (e) => {
                if (e.dataTransfer?.types?.includes('Files')) {
                    e.preventDefault();
                    this.dragging = true;
                }
            });
            window.addEventListener('dragleave', (e) => {
                if (!e.relatedTarget) this.dragging = false;
            });
            window.addEventListener('drop', (e) => {
                e.preventDefault();
                this.dragging = false;
                if (e.dataTransfer?.files?.length) this.addFiles(e.dataTransfer.files);
            });
            const watchSelection = () => setTimeout(() => this.readSelection(), 0);
            document.addEventListener('mouseup', watchSelection);
            document.addEventListener('keyup', watchSelection);
            document.addEventListener('touchend', watchSelection);
            document.addEventListener('scroll', () => (this.selection = null), true);
            // the first page of issues is fetched in the background, so "Existing issues" opens with the list already there
            if (boot.configured && !boot.page?.startsWith?.('all')) setTimeout(() => !this.issuesOpen && !this.issuesLoading && !this.issueResults.length && this.searchIssues(1, true), 1500);
            if (boot.page === 'mine') this.openMine({ push: false });
            else if (boot.page === 'all') this.openIssuesPage({ push: false });
            else if (boot.initialId) this.openConversation(boot.initialId, { push: false });
        },

        // ---- i18n ------------------------------------------------------
        t(key, params = {}) {
            const dict = this.i18n[this.locale] ?? {};
            let v = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), dict);
            if (v === undefined) v = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), this.i18n.en ?? {});
            if (typeof v !== 'string') return key;

            return v.replace(/:(\w+)/g, (m, name) => (params[name] !== undefined ? params[name] : m));
        },
        /** Display label of a catalogue value (type, priority, custom field…); the stored value never changes. */
        lbl(group, value) {
            if (value === null || value === undefined || value === '') return '';

            return this.i18n[this.locale]?.catalog?.[group]?.[value] ?? this.i18n.en?.catalog?.[group]?.[value] ?? value;
        },
        async setLocale(l) {
            if (l === this.locale) return;
            this.locale = l;
            document.documentElement.lang = l;
            this.safe(() => api('PATCH', '/api/preferences', { locale: l }));
        },
        icon(name) {
            return `<svg class="size-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${this.icons[name] ?? ''}</svg>`;
        },
        /** Markdown for chat text and previews; issue keys of the user's projects become links to YouTrack. */
        render(src) {
            return renderText(src, { issueUrl: this.trackerUrl, keys: (this.projects ?? this.catalog?.projects ?? []).map((p) => p.key) });
        },
        size: formatSize,

        // ---- theme / layout ----------------------------------------------
        setTheme(theme) {
            this.theme = theme;
            document.documentElement.dataset.theme = theme;
            this.safe(() => api('PATCH', '/api/preferences', { theme }));
        },
        toggleTheme() {
            this.setTheme(this.theme === 'dark' ? 'light' : 'dark');
        },
        /** Keyboard shortcuts (listed in the "?" dialog). Alt combos use the physical key, so they work with the Mac's Option too. */
        onShortcut(e) {
            if (e.isComposing || e.defaultPrevented) return;
            const el = e.target;
            const typing = el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
            const mod = e.metaKey || e.ctrlKey;
            const run = (fn) => {
                e.preventDefault();
                fn();
            };

            if (mod && !e.altKey && !e.shiftKey && e.code === 'KeyK') {
                return run(() => {
                    if (window.matchMedia('(min-width: 1024px)').matches) this.sidebarCollapsed = false;
                    else this.sidebarOpen = true;
                    this.startHistorySearch();
                });
            }
            if (e.altKey && !mod && !e.shiftKey) {
                const actions = {
                    KeyN: () => this.newChat(),
                    KeyI: () => this.openIssuesPage(),
                    KeyM: () => this.openMine(),
                    KeyD: () => (this.draftEnabled = !this.draftEnabled),
                    KeyB: () => this.toggleSidebar(),
                };
                if (actions[e.code]) return run(actions[e.code]);
            }
            if (!typing && !mod && !e.altKey) {
                if (e.key === '/') return run(() => this.focusComposer());
                if (e.key === '?') return run(() => (this.shortcutsOpen = true));
            }
        },
        toggleSidebar() {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                try {
                    localStorage.setItem('im.sidebar', this.sidebarCollapsed ? '1' : '0');
                } catch (e) {}
            } else {
                this.sidebarOpen = !this.sidebarOpen;
            }
        },
        notify(message, type = 'info') {
            this.toast = { message, type, id: ++uid };
            const id = this.toast.id;
            setTimeout(() => this.toast?.id === id && (this.toast = null), 4500);
        },
        async safe(fn) {
            try {
                return await fn();
            } catch (e) {
                this.notify(e.message || this.t('errors.generic'), 'error');
            }
        },

        // ---- routing / conversations -----------------------------------
        /** History grouped by recency (today / yesterday / last 7 days / older). */
        get groupedConversations() {
            const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
            const today = startOfDay(new Date());
            const day = 86400000;
            const groups = { today: [], yesterday: [], week: [], older: [] };
            const norm = (x) => String(x ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            const q = norm(this.historyQuery.trim());
            for (const c of this.conversations) {
                if (q && !norm(c.title || this.t('nav.untitled')).includes(q)) continue;
                const t = startOfDay(new Date(c.updated_at ?? Date.now()));
                groups[t >= today ? 'today' : t >= today - day ? 'yesterday' : t >= today - 7 * day ? 'week' : 'older'].push(c);
            }

            return Object.entries(groups).filter(([, items]) => items.length).map(([key, items]) => ({ key, items }));
        },
        get activeConversation() {
            return this.conversations.find((c) => c.id === this.activeId) ?? null;
        },
        get title() {
            if (this.page === 'mine') return this.t('mine.title');
            if (this.page === 'all') return this.t('issues.title');

            return this.activeConversation?.title || this.t('app.name');
        },
        routeFromLocation() {
            const m = location.pathname.match(/^\/c\/(\d+)/);
            if (location.pathname === '/issues') this.openMine({ push: false });
            else if (location.pathname === '/issues/all') this.openIssuesPage({ push: false });
            else if (m) this.openConversation(Number(m[1]), { push: false });
            else this.resetChat();
        },
        resetChat() {
            this.page = 'chat';
            this.activeId = null;
            this.messages = [];
            this.draft = null;
            this.advancedOpen = false;
            this.cardTab = 'edit';
            this.pending.clear();
            this.flushBatches();
            this.batches = {};
        },
        newChat() {
            this.stopVoice(true);
            this.resetChat();
            this.sidebarOpen = false;
            this.clearComposer();
            history.pushState({}, '', '/');
            this.$nextTick?.(() => this.focusComposer());
            setTimeout(() => this.focusComposer(), 30);
        },
        async openConversation(id, { push = true } = {}) {
            if (this.loadingConversation) return;
            this.page = 'chat';
            this.sidebarOpen = false;
            this.loadingConversation = true;
            try {
                const data = await api('GET', `/api/conversations/${id}`);
                this.activeId = data.conversation.id;
                this.messages = data.messages;
                this.flushBatches();
                this.draft = data.draft;
                this.batches = {};
                this.advancedOpen = false;
                this.cardTab = 'edit';
                this.pending.clear();
                if (!this.conversations.some((c) => c.id === id)) this.conversations.unshift(data.conversation);
                if (push) history.pushState({}, '', `/c/${id}`);
                if (this.draft?.project) this.loadMeta(this.draft.project);
                this.loadProjects();
                this.ensureCatalog();
                setTimeout(() => this.scrollBottom(true), 30);
            } catch (e) {
                this.notify(e.status === 403 || e.status === 404 ? this.t('errors.conversation_missing') : e.message, 'error');
                this.resetChat();
                history.replaceState({}, '', '/');
            } finally {
                this.loadingConversation = false;
            }
        },
        async renameConversation(c) {
            const title = this.renameValue.trim();
            this.renamingId = null;
            if (!title || title === c.title) return;
            const old = c.title;
            c.title = title;
            try {
                await api('PATCH', `/api/conversations/${c.id}`, { title });
            } catch (e) {
                c.title = old;
                this.notify(e.message, 'error');
            }
        },
        // ---- search the conversations by title -------------------------------
        startHistorySearch() {
            this.historyMenu = false;
            this.historySearching = true;
            setTimeout(() => document.getElementById('history-search')?.focus(), 30);
        },
        stopHistorySearch() {
            this.historySearching = false;
            this.historyQuery = '';
        },
        // ---- multi-select delete -------------------------------------------
        startSelect() {
            this.historyMenu = false;
            this.selectMode = true;
            this.selectedIds = [];
            this.confirmBulk = false;
            this.renamingId = null;
            this.deletingId = null;
        },
        cancelSelect() {
            this.selectMode = false;
            this.selectedIds = [];
            this.confirmBulk = false;
        },
        isSelected(id) {
            return this.selectedIds.includes(id);
        },
        toggleSelect(id) {
            this.confirmBulk = false;
            this.selectedIds = this.isSelected(id) ? this.selectedIds.filter((x) => x !== id) : [...this.selectedIds, id];
        },
        get allSelected() {
            return this.conversations.length > 0 && this.selectedIds.length === this.conversations.length;
        },
        toggleSelectAll() {
            this.confirmBulk = false;
            this.selectedIds = this.allSelected ? [] : this.conversations.map((c) => c.id);
        },
        async deleteSelected() {
            if (!this.selectedIds.length || this.bulkDeleting) return;
            this.bulkDeleting = true;
            const ids = [...this.selectedIds];
            try {
                const res = await api('DELETE', '/api/conversations', { ids });
                const gone = new Set(res.deleted);
                this.conversations = this.conversations.filter((c) => !gone.has(c.id));
                const hadActive = gone.has(this.activeId);
                this.cancelSelect();
                if (hadActive) this.newChat();
                this.notify(this.t('nav.deleted', { n: gone.size }), 'success');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.bulkDeleting = false;
            }
        },
        async deleteConversation(c) {
            this.deletingId = null;
            try {
                await api('DELETE', `/api/conversations/${c.id}`);
                this.conversations = this.conversations.filter((x) => x.id !== c.id);
                if (this.activeId === c.id) this.newChat();
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },

        // ---- messages --------------------------------------------------
        get lastCardId() {
            const card = [...this.messages].reverse().find((m) => m.meta?.card);
            if (card) return card.id;

            // a direct creation (draft mode off) that failed: bring the editable card back so it can be fixed and retried
            return this.draft?.status === 'error' ? ([...this.messages].reverse().find((m) => m.role === 'assistant' && m.kind === 'text')?.id ?? null) : null;
        },
        scrollBottom(instant = false) {
            const el = document.getElementById('chat-scroll');
            if (el) el.scrollTo({ top: el.scrollHeight, behavior: instant ? 'auto' : 'smooth' });
        },
        focusComposer() {
            document.getElementById('composer-input')?.focus();
        },
        useSuggestion(key) {
            this.composer.text = this.t(`empty.suggestions.${key}.prompt`);
            this.focusComposer();
            setTimeout(() => {
                const el = document.getElementById('composer-input');
                if (el) el.setSelectionRange(el.value.length, el.value.length);
            }, 30);
        },
        get canSend() {
            const c = this.composer;
            return c.text.trim() !== '' || c.files.length > 0 || this.attachedIssues.length > 0;
        },
        /** Conversation so far as plain text turns for the chat API (attachments of past turns are only named). */
        historyForApi() {
            return this.messages
                .filter((m) => (m.role === 'user' || m.kind === 'text' || m.kind === 'question') && (m.body || m.attachments?.length || this.issuesOf(m).length))
                .map((m) => {
                    const names = (m.attachments ?? []).map((a) => a.name);
                    const note = names.length ? `\n\n[${this.t('chat.previous_attachments')}: ${names.join(', ')}]` : '';

                    return { role: m.role, content: `${m.role === 'user' ? this.issueTag(this.issuesOf(m)) : ''}${m.body ?? ''}${note}`.trim() };
                })
                .filter((m) => m.content !== '');
        },
        /** The issues that went with a message (attached like its files). */
        issuesOf(m) {
            return m.meta?.contexts ?? (m.meta?.context ? [m.meta.context] : []);
        },
        /** What tells Claude which issues a message carried: `[Contexto: DEV-1, DEV-2] `. */
        issueTag(issues) {
            return issues.length ? `[Contexto: ${issues.map((i) => i.key).join(', ')}] ` : '';
        },
        /** Projects + per-project metadata (types, priorities, people, custom fields) for Claude and the card. */
        async ensureCatalog() {
            if (this.catalog) return this.catalog;
            try {
                const c = await api('GET', '/api/youtrack/catalog');
                this.catalog = c;
                this.projects = this.projects ?? c.projects;
                this.metaByProject = { ...c.byProject, ...this.metaByProject };
            } catch (e) {
                this.notify(e.message, 'error');

                return {};
            }

            return this.catalog;
        },
        // ---- quick replies (clickable answers under the last assistant message) ----
        get quickReplies() {
            const last = this.messages.at(-1);
            if (this.processing || last?.role !== 'assistant' || !last.meta?.replies?.length) return [];

            return last.meta.replies.map((r) => this.resolveReply(r));
        },
        /** People and projects get their avatar/icon from the catalogue; unknown values stay plain text buttons. */
        resolveReply(r) {
            let option = null;
            if (r.kind === 'person') {
                const people = Object.values(this.catalog?.byProject ?? {}).flatMap((m) => m.assignees ?? []);
                const a = people.find((x) => x.value === r.value);
                if (a) option = this.personOption(a);
            } else if (r.kind === 'project') {
                const p = (this.projects ?? []).find((x) => x.key === r.value);
                if (p) option = this.projectOption(p);
            }

            return { ...r, option };
        },
        pickReply(r) {
            if (this.processing) return;
            if (r.kind === 'action' && r.value === 'choose_assignee') {
                this.cardTab = 'edit';
                this.focusField('assignee');
                setTimeout(() => document.getElementById('field-assignee')?.click(), 350);

                return;
            }
            this.send(r.text || r.label);
        },
        /** Dropdown option with the project icon (initials underneath while it loads or if it is missing). */
        projectOption(p) {
            return { value: p.key, label: `${p.key} · ${p.name}`, image: p.icon ?? null, shape: 'square', fallback: String(p.key).slice(0, 2).toUpperCase() };
        },
        /** Dropdown option with the person's YouTrack avatar (round, initials as fallback). */
        personOption(a) {
            const initials = String(a.label ?? a.value).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

            return { value: a.value, label: a.label, image: a.avatar ?? null, shape: 'round', fallback: initials };
        },
        /** The catalogue Claude receives has no image URLs (they only cost tokens). */
        catalogForApi(c) {
            if (!c?.projects) return c;

            return {
                projects: c.projects.map(({ key, name }) => ({ key, name })),
                byProject: Object.fromEntries(Object.entries(c.byProject ?? {}).map(([k, m]) => [k, { ...m, assignees: (m.assignees ?? []).map(({ value, label }) => ({ value, label })) }])),
            };
        },
        peopleFor(catalog, project) {
            return catalog?.byProject?.[project]?.assignees ?? [];
        },
        /** The stop button: ends the answer being written; the message stays and the reply becomes "message cancelled". */
        stopAnswer() {
            answerAbort?.abort('stop');
        },
        async send(textOverride = null, filesOverride = null, issuesOverride = null) {
            const fromComposer = textOverride === null;
            const typed = (fromComposer ? this.composer.text : textOverride).trim();
            const body = fromComposer && this.composer.quote && typed ? `${quoteBlock(this.composer.quote)}\n\n${typed}` : typed;
            const files = fromComposer ? [...this.composer.files] : [...(filesOverride ?? [])];
            const issues = fromComposer ? [...this.attachedIssues] : [...(issuesOverride ?? [])]; // attached to THIS message only, like the files
            if (!body && !files.length && !issues.length) return;

            // sending ends the dictation for good: what it still had to say belongs to nothing now
            this.stopVoice(true);

            // The previous answer is still being written: keep this message in line (shown above the box)
            // instead of silently leaving it in the text area.
            if (this.processing) {
                this.queue.push({ body, files, issues });
                if (fromComposer) this.clearComposer(false);

                return;
            }

            this.processing = true;
            this.streaming = false;
            this.elapsed = 0;
            const startedAt = Date.now();
            clearInterval(elapsedTimer);
            elapsedTimer = setInterval(() => (this.elapsed = Math.floor((Date.now() - startedAt) / 1000)), 1000);
            let live = null;

            // What Claude has seen so far (before this message), then show the message and empty the box right away:
            // nothing below waits on the network before the user sees the message leave the text area.
            const turns = this.historyForApi();
            const temp = {
                id: `tmp-${++uid}`,
                role: 'user',
                kind: 'text',
                body,
                meta: issues.length ? { contexts: issues.map((i) => ({ key: i.key, summary: i.summary })) } : {},
                attachments: files.map((f) => ({ id: f.id, kind: f.kind, name: f.name, size: f.size, url: f.preview })),
            };
            this.messages.push(temp);
            if (fromComposer) this.clearComposer(false);
            setTimeout(() => this.scrollBottom(), 30);

            try {
                if (!this.activeId) {
                    const c = await api('POST', '/api/conversations');
                    this.conversations.unshift(c);
                    this.activeId = c.id;
                    history.pushState({}, '', `/c/${c.id}`);
                    this.loadProjects();
                }

                // "no / nobody" after the app asked about an assignee: stop asking in this conversation
                const lastAssistant = [...this.messages].reverse().find((m) => m.role === 'assistant');
                if (lastAssistant?.meta?.asked_assignee && /^\s*(n[ãa]o|ningu[ée]m|no|nobody|none|deixa|leave|sem )/i.test(body)) this.assigneeDeclined.add(this.activeId);
                const sentBody = body || (files.length || issues.length ? this.t('chat.attachments_only') : '');
                // the new message is tagged like the earlier ones, so "this issue" in it cannot be taken for the one of an earlier message
                turns.push({ role: 'user', content: `${this.issueTag(issues)}${sentBody}` });
                const catalog = this.catalogForApi(await this.ensureCatalog());

                live = { id: `tmp-${++uid}`, role: 'assistant', kind: 'text', body: '', meta: {}, attachments: [] };
                let text = '';
                const ctl = (answerAbort = new AbortController());
                ctl.timer = setTimeout(() => ctl.abort(new DOMException('timeout', 'TimeoutError')), 330000); // a stalled connection must not leave the chat waiting forever
                const { patch: draftPatch, created, updated, replies: modelReplies, batch, stopped } = await streamChat(
                    {
                        payload: {
                            messages: turns,
                            draft_enabled: this.draftEnabled,
                            draft: this.draft ? this.draftForApi() : null,
                            contexts: issues.map((i) => ({ key: i.key })),
                            catalog,
                        },
                        files: files.map((f) => f.file),
                        signal: ctl.signal,
                    },
                    {
                        onText: (t) => {
                            text += t;
                            if (!this.streaming) {
                                this.streaming = true;
                                this.messages.push(live);
                            }
                            live.body = text;
                            this.stalled = false;
                            clearTimeout(stallTimer);
                            stallTimer = setTimeout(() => (this.stalled = this.processing && this.streaming), 700);
                            this.scrollBottomIfNear();
                        },
                    },
                );

                clearTimeout(ctl.timer);
                answerAbort = null;
                // stopped by the user: the message stays in the chat and what Claude had written is dropped; the reply just says so
                if (stopped) {
                    text = this.t('chat.cancelled');
                    live.body = text;
                }

                // draft mode off: Claude may call create_issue when the user explicitly asks for an issue
                // several issues proposed from a pasted text: reviewed one by one in a checklist, never created right away
                const proposals = batch?.issues?.length ? batch.issues : null;
                const direct = !proposals && !this.draftEnabled && !!created;
                const patch = proposals ? null : direct ? created : draftPatch;
                if (proposals && batch.message?.trim()) text = batch.message;
                if (proposals && !text.trim()) text = this.t('batch.found', { n: proposals.length });

                if (patch?.message && typeof patch.message === 'string') {
                    text = patch.message;
                    delete patch.message;
                }
                if (!text.trim() && modelReplies?.length) text = this.t('chat.pick_one');
                if (!text.trim() && updated?.message) text = updated.message;
                if (!text.trim() && updated) text = this.t('chat.updating_issue');
                if (!text.trim() && patch) text = this.t(direct ? 'chat.creating_issue' : 'chat.draft_updated');

                // Always offer an assignee while the draft has none (done by the app so it never depends on the model)
                let askedAssignee = false;
                const current = patch?.new_issue ? null : this.draft; // "another issue": nothing of the one before carries over
                const people = this.peopleFor(catalog, patch?.project ?? current?.project);
                const named = people.some((a) => text.toLowerCase().includes(a.label.split(' ')[0].toLowerCase()));
                const hasAssignee = (patch?.assignee && people.some((a) => a.value === patch.assignee)) || current?.assignee;
                if (this.draftEnabled && patch && !hasAssignee && !named && !this.assigneeDeclined.has(this.activeId) && people.length) {
                    text = `${text.trim()}\n\n${this.t('chat.ask_assignee', { names: people.map((a) => a.label).join(', ') })}`;
                    askedAssignee = true;
                } else if (named && text.includes('?') && !hasAssignee) askedAssignee = true;

                // clickable answers: the model's, or (always, even if it forgot) the two obvious ones after the assignee question
                let replies = modelReplies ?? [];
                if (askedAssignee && !replies.length) {
                    replies = [
                        { label: this.t('chat.no_assignee'), text: this.t('chat.no_assignee_text'), kind: 'text', value: null },
                        { label: this.t('chat.choose_assignee'), text: '', kind: 'action', value: 'choose_assignee' },
                    ];
                }

                if (!text.trim()) throw new Error(this.t('errors.chat_failed'));

                const fd = new FormData();
                fd.append('body', body);
                if (issues.length) fd.append('contexts', JSON.stringify(issues.map((i) => ({ key: i.key, summary: i.summary }))));
                fd.append('assistant', JSON.stringify({ text, draft_patch: this.draftEnabled || direct ? patch : null, direct, batch: proposals, asked_assignee: askedAssignee, replies }));
                files.forEach((f) => fd.append('files[]', f.file));
                const res = await api('POST', `/api/conversations/${this.activeId}/turn`, fd);

                files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
                this.messages = this.messages.filter((m) => m.id !== temp.id && m.id !== live.id);
                this.messages.push(...res.messages);
                this.draft = res.draft;
                this.pending.clear();
                const conv = this.conversations.find((c) => c.id === res.conversation.id);
                if (conv) Object.assign(conv, res.conversation);
                this.conversations = [...this.conversations].sort((a, b) => (a.id === res.conversation.id ? -1 : b.id === res.conversation.id ? 1 : 0));
                if (this.draft?.project) this.loadMeta(this.draft.project);
                setTimeout(() => this.scrollBottom(), 40);

                if (direct && res.messages[1]?.meta?.direct) await this.createDirect();
                if (updated?.issue_id) await this.applyIssueEdit(updated);
            } catch (e) {
                if (temp) this.messages = this.messages.filter((m) => m.id !== temp.id && m.id !== live?.id);
                if (temp && fromComposer) {
                    // give the message back without overwriting whatever was typed in the meantime
                    this.composer.text = [body, this.composer.text].filter(Boolean).join(this.composer.text ? '\n' : '');
                    this.composer.files = [...files, ...this.composer.files];
                    this.attachedIssues = [...issues, ...this.attachedIssues.filter((a) => !issues.some((i) => i.key === a.key))];
                }
                this.notify(e.name === 'TimeoutError' || e.name === 'AbortError' ? this.t('errors.chat_failed') : e.message || this.t('errors.generic'), 'error');
            } finally {
                if (answerAbort) clearTimeout(answerAbort.timer);
                answerAbort = null;
                this.processing = false;
                this.streaming = false;
                this.stalled = false;
                clearTimeout(stallTimer);
                clearInterval(elapsedTimer);
                const next = this.queue.shift();
                if (next) setTimeout(() => this.send(next.body, next.files, next.issues), 0);
            }
        },
        // ---- batch of proposed issues (one pasted text -> N issues, swiped through like a deck) ----
        // Every proposal is decided one by one (decision: null -> accepted | rejected); only when all are decided can the
        // accepted ones be created. `status` is the server side: pending -> created | discarded.
        batchItems(m) {
            return m.meta?.batch?.items ?? [];
        },
        /** Local, editable copy of the proposals of message `m` (kept apart so edits survive re-renders). */
        batchState(m) {
            if (!this.batches[m.id]) {
                this.batches[m.id] = {
                    conv: this.activeId, // where to save it, even after the user moved to another conversation
                    mid: m.id,
                    dirty: false,
                    busy: false,
                    viewing: null, // index of an accepted proposal opened for editing
                    history: this.batchItems(m).map((it, i) => (it.status === 'pending' && it.decision ? i : null)).filter((i) => i !== null), // decided indexes, newest last (for "back")
                    rows: this.batchItems(m).map((it) => ({
                        ...it,
                        labels: [...(it.labels ?? [])],
                        custom_fields: { ...(it.custom_fields ?? {}) },
                        attachments: [...(it.attachments ?? [])],
                        decision: it.status === 'created' ? 'accepted' : it.status === 'discarded' ? 'rejected' : (it.decision ?? null),
                        similar: [], similarKey: null, dupIds: '', dupAck: false, // look-alikes of this proposal (client only)
                    })),
                };
                [...new Set(this.batchItems(m).map((it) => it.project).filter(Boolean))].forEach((p) => this.loadMeta(p));
            }

            return this.batches[m.id];
        },
        /** The review changed (an edit or a decision): saved on the server after a short pause, so a reload keeps it. */
        batchTouch(m) {
            const st = this.batchState(m);
            st.dirty = true;
            clearTimeout(batchSaveTimers.get(st.mid));
            batchSaveTimers.set(st.mid, setTimeout(() => this.batchSave(st), 700));
        },
        async batchSave(st, { keepalive = false } = {}) {
            clearTimeout(batchSaveTimers.get(st.mid));
            if (!st.dirty || st.busy) return;
            st.dirty = false;
            const items = st.rows.map((r, index) => ({ r, index })).filter(({ r }) => r.status === 'pending').map(({ r, index }) => ({
                index, summary: r.summary, description: r.description, project: r.project, type: r.type, priority: r.priority,
                assignee: r.assignee ?? '', subsystem: r.subsystem ?? '', labels: r.labels ?? [], custom_fields: r.custom_fields ?? {}, attachments: r.attachments ?? [], decision: r.decision ?? null,
            }));
            if (!items.length) return;
            try {
                await api('PATCH', `/api/conversations/${st.conv}/messages/${st.mid}/batch`, { items }, { keepalive });
            } catch (e) {
                st.dirty = true; // tried again with the next change
                if (!st.saveFailed) this.notify(this.t('batch.save_failed'), 'error');
                st.saveFailed = true;

                return;
            }
            st.saveFailed = false;
        },
        /** Before leaving the conversation or the page: nothing typed in the last moments may be lost. */
        flushBatches() {
            Object.values(this.batches).forEach((st) => st.dirty && this.batchSave(st, { keepalive: true }));
        },
        /** Index of the next proposal still to decide, or null when all are decided. */
        batchCursor(m) {
            const i = this.batchState(m).rows.findIndex((r) => r.status === 'pending' && !r.decision);

            return i < 0 ? null : i;
        },
        batchPending(m) {
            return this.batchState(m).rows.filter((r) => r.status === 'pending');
        },
        batchAccepted(m) {
            return this.batchState(m).rows.map((r, index) => ({ r, index })).filter(({ r }) => r.decision === 'accepted');
        },
        batchToCreate(m) {
            return this.batchAccepted(m).filter(({ r }) => r.status === 'pending');
        },
        batchRejected(m) {
            return this.batchPending(m).filter((r) => r.decision === 'rejected');
        },
        batchMissing(row) {
            return REQUIRED.filter((f) => !String(row[f] ?? '').trim());
        },
        /** Same interface as the store's single draft (draft, meta, missing, setField…), so the normal issue card can edit one proposal. */
        batchScope(m, i) {
            const key = `${m.id}:${i}`;
            if (!batchScopes.has(key)) {
                const self = this;
                const row = () => self.batchState(m).rows[i];
                batchScopes.set(key, {
                    index: i,
                    get total() {
                        return self.batchState(m).rows.length;
                    },
                    get draft() {
                        return row();
                    },
                    get meta() {
                        return self.metaByProject[row()?.project] ?? EMPTY_META;
                    },
                    get missing() {
                        return self.batchMissing(row());
                    },
                    get valid() {
                        return this.missing.length === 0;
                    },
                    get similarIssues() {
                        return row()?.similar ?? [];
                    },
                    /** Files of the conversation that go to this proposal's issue. */
                    hasAttachment(a) {
                        return (row()?.attachments ?? []).includes(a.id);
                    },
                    get attachmentCount() {
                        return (row()?.attachments ?? []).length;
                    },
                    get allAttachments() {
                        const ids = self.conversationAttachments.map((a) => a.id);

                        return ids.length > 0 && ids.every((id) => (row()?.attachments ?? []).includes(id));
                    },
                    toggleAllAttachments() {
                        row().attachments = this.allAttachments ? [] : self.conversationAttachments.map((a) => a.id);
                        self.batchTouch(m);
                    },
                    toggleAttachment(a) {
                        const r = row();
                        r.attachments = r.attachments.includes(a.id) ? r.attachments.filter((x) => x !== a.id) : [...r.attachments, a.id];
                        self.batchTouch(m);
                    },
                    get duplicates() {
                        return this.similarIssues.filter((x) => x.duplicate);
                    },
                    setField(field, value) {
                        if (field === 'project') self.batchSetProject(row(), value ?? '');
                        else row()[field] = value;
                        self.batchTouch(m);
                    },
                    setCustom(key2, value) {
                        row().custom_fields = { ...row().custom_fields, [key2]: value };
                        self.batchTouch(m);
                    },
                    addLabel(raw) {
                        const label = raw.trim().replace(/,$/, '');
                        if (label && !row().labels.includes(label)) {
                            row().labels = [...row().labels, label];
                            self.batchTouch(m);
                        }
                    },
                    removeLabel(label) {
                        row().labels = row().labels.filter((l) => l !== label);
                        self.batchTouch(m);
                    },
                });
            }

            return batchScopes.get(key);
        },
        batchSetProject(row, project) {
            row.project = project;
            row.type = row.priority = row.assignee = row.subsystem = row.sprint = '';
            row.custom_fields = {};
            this.loadMeta(project);
        },
        /** Look-alikes of a proposal as currently edited, after a pause in typing (shown in its card). */
        batchWatchSimilar(m, i) {
            const row = this.batchState(m).rows[i];
            if (!row || row.status !== 'pending') return;
            const key = `${row.project}|${row.summary}|${String(row.description ?? '').trim().slice(0, 1500)}`;
            clearTimeout(batchSimilarTimer);
            if (!row.project || !String(row.summary ?? '').trim() || row.similarKey === key) return;
            batchSimilarTimer = setTimeout(() => this.batchRefreshSimilar(m, i), 800);
        },
        /** Same check without the pause: approving must not rely on a look-up that is still pending. */
        async batchRefreshSimilar(m, i) {
            const row = this.batchState(m).rows[i];
            if (!row || row.status !== 'pending' || !row.project || !String(row.summary ?? '').trim()) return;
            clearTimeout(batchSimilarTimer);
            const description = String(row.description ?? '').trim().slice(0, 1500);
            try {
                const res = await api('GET', `/api/conversations/${this.activeId}/messages/${m.id}/batch/${i}/similar?` + new URLSearchParams({ summary: row.summary, project: row.project, description }));
                row.similar = res.issues ?? [];
                row.similarKey = `${row.project}|${row.summary}|${description}`;
                // an acknowledgement only counts for the duplicates it was given for
                const ids = row.similar.filter((x) => x.duplicate).map((x) => x.id).join(',');
                if (ids !== row.dupIds) {
                    row.dupIds = ids;
                    row.dupAck = false;
                }
            } catch (_) {
                // only a warning: if it cannot be checked, approving is still possible
            }
        },
        /** Approving needs the required fields and, for a probable duplicate, an explicit "this is not a duplicate"; rejecting is always possible. */
        async batchCanDecide(m, i, decision) {
            const st = this.batchState(m);
            if (st.busy) return false;
            if (decision !== 'accepted') return true;
            const row = st.rows[i];
            if (this.batchMissing(row).length) {
                this.notify(this.t('batch.missing'), 'error');

                return false;
            }
            await this.batchRefreshSimilar(m, i);
            if (row.similar.some((x) => x.duplicate) && !row.dupAck) {
                this.notify(this.t('batch.duplicate_blocked'), 'error');

                return false;
            }

            return true;
        },
        batchDecide(m, i, decision) {
            const st = this.batchState(m);
            if (st.busy) return false;
            st.rows[i].decision = decision;
            if (st.viewing === i) st.viewing = null;
            st.history = [...st.history.filter((x) => x !== i), i];
            this.batchTouch(m);

            return true;
        },
        /** Undo the last decision: that proposal comes back to be decided again (with its edits). */
        batchBack(m) {
            const st = this.batchState(m);
            const i = [...st.history].reverse().find((x) => st.rows[x]?.status === 'pending');
            if (i === undefined) return;
            st.history = st.history.filter((x) => x !== i);
            st.rows[i].decision = null;
            st.rows[i].error = null;
            st.viewing = null;
            this.batchTouch(m);
        },
        /** Creates the accepted proposals (with the edits made in their cards) and discards the rejected ones. */
        async batchCreate(m) {
            const st = this.batchState(m);
            if (st.busy || this.batchCursor(m) !== null) return;
            const accepted = this.batchToCreate(m);
            const bad = accepted.find(({ r }) => this.batchMissing(r).length);
            if (bad) {
                st.viewing = bad.index;

                return this.notify(this.t('batch.missing'), 'error');
            }
            await this.batchSend(m, {
                create: accepted.map(({ r, index }) => ({ index, summary: r.summary, description: r.description, project: r.project, type: r.type, priority: r.priority, assignee: r.assignee ?? '', subsystem: r.subsystem ?? '', labels: r.labels ?? [], custom_fields: r.custom_fields ?? {}, attachments: r.attachments ?? [] })),
                discard: st.rows.map((r, index) => ({ r, index })).filter(({ r }) => r.status === 'pending' && r.decision === 'rejected').map(({ index }) => index),
            });
        },
        async batchSend(m, body) {
            const st = this.batchState(m);
            clearTimeout(batchSaveTimers.get(st.mid));
            st.dirty = false;
            st.busy = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/messages/${m.id}/batch`, body);
                const old = st.rows;
                const at = this.messages.findIndex((x) => x.id === m.id);
                if (at >= 0) this.messages[at] = res.message;
                // a failed creation stays accepted (with its edits and the server's error); the rest shows the saved state
                st.rows = res.message.meta.batch.items.map((it, i) =>
                    it.status === 'pending' && old[i]
                        ? { ...old[i], error: it.error ?? null }
                        : { ...it, labels: it.labels ?? [], custom_fields: it.custom_fields ?? {}, attachments: it.attachments ?? [], decision: it.status === 'created' ? 'accepted' : 'rejected' },
                );
                st.viewing = null;
                const items = res.message.meta.batch.items;
                const created = items.filter((it) => it.status === 'created').length;
                const failed = items.filter((it) => it.status === 'pending' && it.error).length;
                this.notify(failed ? this.t('batch.partial', { ok: created, failed }) : this.t('batch.done'), failed ? 'error' : 'success');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                st.busy = false;
            }
        },
        /** Throws the draft away (the card disappears; the next request starts a new one). */
        async discardDraft() {
            if (!this.draft || this.draft.status === 'created') return;
            try {
                await api('DELETE', `/api/conversations/${this.activeId}/draft`);
                this.draft = null;
                this.similarIssues = [];
                this.advancedOpen = false;
                this.cardTab = 'edit';
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        /** Draft mode off + explicit request: the issue is created right away and shown as result + preview. */
        async createDirect() {
            this.creatingKind = 'create';
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/draft/create`, { confirmed: true, direct: true });
                this.draft = res.draft;
                this.messages.push(res.message);
                this.syncConversation(res.conversation);
                setTimeout(() => this.scrollBottom(), 40);
            } catch (e) {
                if (e.data?.draft) this.draft = e.data.draft;
                this.notify(e.message, 'error');
            } finally {
                this.creating = false;
            }
        },
        /** The user asked Claude to change an existing issue (by key, title or "the one I just created"). */
        async applyIssueEdit(edit) {
            const { issue_id: issueId, message, ...fields } = edit; // eslint-disable-line no-unused-vars
            this.creatingKind = 'update';
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/issues/update`, { issue_id: issueId, ...fields });
                this.messages.push(res.message);
                this.syncConversation(res.conversation);
                if (res.draft) this.draft = res.draft;
                setTimeout(() => this.scrollBottom(), 40);
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.creating = false;
            }
        },
        draftForApi() {
            const d = this.draft;

            return { summary: d.summary, description: d.description, project: d.project, type: d.type, priority: d.priority, assignee: d.assignee, labels: d.labels, sprint: d.sprint, subsystem: d.subsystem, custom_fields: d.custom_fields, issue_key: d.issue_key, suggestions: d.suggestions };
        },
        scrollBottomIfNear() {
            const el = document.getElementById('chat-scroll');
            if (el && el.scrollHeight - el.scrollTop - el.clientHeight < 160) el.scrollTo({ top: el.scrollHeight });
        },
        // ---- voice dictation -----------------------------------------------
        // The browser's recognizer (Web Speech API) ends a "continuous" session by itself after a pause or a minute, and delivers
        // its results per session. A dictation is therefore a series of sessions that the app chains until the user stops, and the
        // text shown is rebuilt from everything said so far, cleaned up by voice.js (spoken punctuation, capitals, issue ids).
        toggleVoice() {
            return this.voice.listening ? this.stopVoice() : this.startVoice();
        },
        startVoice() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return this.notify(this.t('errors.voice_unsupported'), 'error');
            if (this.processing || this.voice.listening) return;

            const session = { wanted: true, fatal: false, rec: null, base: this.composer.text.trim(), committed: '', final: '', interim: '', starts: [] };
            voiceSession = session;
            this.voice.listening = true;
            this.focusComposer();
            this.launchVoice(session);
        },
        launchVoice(session) {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            const rec = new SR();
            rec.lang = this.locale === 'pt' ? 'pt-PT' : 'en-US';
            rec.continuous = true;
            rec.interimResults = true;
            rec.maxAlternatives = 5; // several guesses per phrase: the one that knows the app's own words wins (see pickAlternative)
            const vocabulary = vocabularyWords({ keys: this.voiceKeys(), names: this.voiceNames() });

            rec.onresult = (e) => {
                // late results of a dictation that was cancelled (the message was sent and the box emptied) are ignored
                if (voiceSession !== session) return;
                let final = '';
                let interim = '';
                for (let i = 0; i < e.results.length; i++) {
                    const r = e.results[i];
                    const said = pickAlternative(r, vocabulary);
                    if (r.isFinal) final += said;
                    else interim += said;
                }
                session.final = final;
                session.interim = interim;
                this.renderVoice(session);
            };
            rec.onerror = (e) => {
                if (e.error === 'no-speech' || e.error === 'aborted') return; // silence: the next session simply starts again
                session.fatal = true;
                const key = { 'not-allowed': 'voice_denied', 'service-not-allowed': 'voice_denied', 'audio-capture': 'voice_no_mic', network: 'voice_network' }[e.error] ?? 'voice_failed';
                this.notify(this.t(`errors.${key}`), 'error');
            };
            rec.onend = () => {
                if (voiceSession !== session) return;
                // what this session heard (even a last provisional phrase) is kept for the text of the whole dictation
                session.committed = [session.committed, `${session.final}${session.interim}`.trim()].filter(Boolean).join(' ');
                session.final = session.interim = '';

                if (session.wanted && !session.fatal) {
                    const now = Date.now();
                    session.starts = [...session.starts.filter((t) => now - t < 5000), now];
                    if (session.starts.length > 6) {
                        // restarting over and over without anything being heard: give up instead of looping
                        this.notify(this.t('errors.voice_failed'), 'error');
                    } else {
                        setTimeout(() => voiceSession === session && session.wanted && this.launchVoice(session), 120);

                        return;
                    }
                }
                this.voice.listening = false;
                voiceSession = null;
                this.renderVoice(session);
            };

            try {
                rec.start();
                session.rec = rec;
            } catch (e) {
                session.fatal = true;
                this.voice.listening = false;
                voiceSession = null;
                this.notify(this.t('errors.voice_failed'), 'error');
            }
        },
        /** Project keys and people of the user: what the dictation should recognise (issue ids, names with capitals). */
        voiceKeys() {
            return (this.projects ?? this.catalog?.projects ?? []).map((p) => p.key);
        },
        voiceNames() {
            const metas = Object.values(this.catalog?.byProject ?? this.metaByProject ?? {});

            return [...new Set(metas.flatMap((m) => (m.assignees ?? []).map((a) => a.label)).filter(Boolean))];
        },
        /** Box = what was typed before + everything dictated so far, cleaned up. */
        renderVoice(session) {
            const raw = [session.committed, session.final, session.interim].map((x) => x.trim()).filter(Boolean).join(' ');
            const said = polishDictation(raw, { locale: this.locale, keys: this.voiceKeys(), names: this.voiceNames(), atStart: endsSentence(session.base) });
            this.composer.text = said ? `${session.base}${session.base === '' || session.base.endsWith('\n') ? '' : ' '}${said}` : session.base;
            this.followDictation();
        },
        /**
         * The box grows with the text up to its maximum height and then scrolls. Typing keeps the caret in view, but text put
         * there by the dictation does not, so the newest words would stay out of sight below the edge: show the end of the text.
         */
        followDictation() {
            setTimeout(() => {
                // after the text has been written into the box (x-model) and it has been resized
                const el = document.getElementById('composer-input');
                if (!el) return;
                this.autosize(el);
                el.scrollTop = el.scrollHeight;
                el.setSelectionRange?.(el.value.length, el.value.length);
            }, 0);
        },
        /**
         * Stops dictating. Normally (the microphone button) the last words are still delivered; with `discard` (the message is
         * being sent, or the box is being emptied) the recognition is cut off and nothing it says afterwards may come back.
         */
        stopVoice(discard = false) {
            const session = voiceSession;
            if (session) {
                session.wanted = false;
                if (discard) voiceSession = null; // from now on its late results are ignored (see onresult)
                try {
                    discard ? session.rec?.abort() : session.rec?.stop();
                } catch (e) {}
            }
            this.voice.listening = false;
        },
        onComposerKey(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                this.send();
            }
        },
        // ---- replying to a passage of an answer ----------------------------
        readSelection() {
            const sel = window.getSelection();
            const text = sel?.toString().trim() ?? '';
            const node = sel?.anchorNode?.nodeType === 1 ? sel.anchorNode : sel?.anchorNode?.parentElement;
            const inAnswer = node?.closest?.('.prose-msg') && sel.focusNode && node.closest('.prose-msg').contains(sel.focusNode);
            if (!text || !inAnswer) {
                this.selection = null;
                return;
            }
            const r = sel.getRangeAt(0).getBoundingClientRect();
            this.selection = { text, x: Math.min(Math.max(r.left + r.width / 2, 60), window.innerWidth - 60), y: Math.max(r.top - 8, 48) };
        },
        quoteSelection() {
            if (!this.selection) return;
            this.composer.quote = this.selection.text.slice(0, QUOTE_MAX);
            this.selection = null;
            window.getSelection()?.removeAllRanges();
            document.getElementById('composer-input')?.focus();
        },
        // a user message body that starts with quoted lines ("> ...") shows them as a quote above the text
        splitQuote(body) {
            const m = /^((?:>[^\n]*\n)+)\n?([\s\S]*)$/.exec(body ?? '');
            return m ? { quote: m[1].replace(/^> ?/gm, '').trim(), text: m[2] } : { quote: '', text: body ?? '' };
        },
        // 7 -> "7 s", 75 -> "1 min 15 s"
        elapsedLabel() {
            const m = Math.floor(this.elapsed / 60);
            return m ? `${m} min ${this.elapsed % 60} s` : `${this.elapsed} s`;
        },
        autosize(el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 220)}px`;
        },

        // ---- attachments -----------------------------------------------
        addFiles(list) {
            for (const file of Array.from(list)) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (this.composer.files.length >= MAX_FILES) {
                    this.notify(this.t('errors.too_many_files', { max: MAX_FILES }), 'error');
                    break;
                }
                if (BLOCKED_EXT.includes(ext)) {
                    this.notify(this.t('errors.file_type', { name: file.name }), 'error');
                    continue;
                }
                if (file.size > MAX_BYTES) {
                    this.notify(this.t('errors.file_size', { name: file.name }), 'error');
                    continue;
                }
                const isImage = file.type.startsWith('image/');
                this.composer.files.push({
                    id: `f-${++uid}`,
                    file,
                    name: file.name,
                    size: file.size,
                    kind: isImage ? 'image' : 'file',
                    preview: isImage ? URL.createObjectURL(file) : null,
                });
            }
        },
        onPaste(e) {
            const files = Array.from(e.clipboardData?.files ?? []);
            if (files.length) {
                e.preventDefault();
                this.addFiles(files);
            }
        },
        removeFile(id) {
            const f = this.composer.files.find((x) => x.id === id);
            if (f?.preview) URL.revokeObjectURL(f.preview);
            this.composer.files = this.composer.files.filter((x) => x.id !== id);
        },
        clearComposer(revoke = true) {
            this.stopVoice(true); // an open microphone would write its transcript back into the emptied box
            if (revoke) this.composer.files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
            this.composer = { text: '', files: [], quote: '' };
            this.attachedIssues = [];
            const el = document.getElementById('composer-input');
            if (el) el.style.height = 'auto';
        },

        // ---- tracker metadata --------------------------------------------
        async loadProjects() {
            if (this.projects) return;
            try {
                this.projects = (await api('GET', '/api/youtrack/projects')).projects;
            } catch (e) {
                this.projects = [];
                this.notify(e.message, 'error');
            }
        },
        async loadMeta(project) {
            if (!project || this.metaByProject[project]) return;
            try {
                this.metaByProject = { ...this.metaByProject, [project]: await api('GET', `/api/youtrack/projects/${encodeURIComponent(project)}/meta`) };
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        get meta() {
            return this.metaByProject[this.draft?.project] ?? this.metaByProject[this.projects?.[0]?.key] ?? { types: [], priorities: [], subsystems: [], sprints: [], assignees: [], custom: [] };
        },

        // ---- attachments that go to the YouTrack issue ----------------------
        /** Every file/image sent in this conversation (they can be attached to the issue). */
        get conversationAttachments() {
            return this.messages
                .filter((m) => m.role === 'user')
                .flatMap((m) => m.attachments ?? [])
                .filter((a) => typeof a.id === 'number' && a.url);
        },
        get pendingAttachmentCount() {
            return this.conversationAttachments.filter((a) => a.to_issue && !a.uploaded_to).length;
        },
        async toggleAttachment(a) {
            const next = !a.to_issue;
            a.to_issue = next;
            try {
                await api('PATCH', `/api/attachments/${a.id}`, { to_issue: next });
            } catch (e) {
                a.to_issue = !next;
                this.notify(e.message, 'error');
            }
        },

        // ---- draft (the single issue card) -------------------------------
        get missing() {
            return this.draft ? REQUIRED.filter((f) => !String(this.draft[f] ?? '').trim()) : [];
        },
        get valid() {
            return this.draft && this.missing.length === 0;
        },
        // ---- similar issues (duplicate warning) --------------------------
        watchSimilar(summary, project, description = '') {
            clearTimeout(similarTimer);
            const text = String(description ?? '').trim().slice(0, 1500);
            const key = `${project}|${summary}|${text}`;
            if (!this.activeId || !this.draft || this.draft.issue_key || !project || !String(summary ?? '').trim()) {
                this.similarKey = null;
                this.similarIssues = [];
                return;
            }
            if (key === this.similarKey) return;
            const id = this.activeId;
            similarTimer = setTimeout(async () => {
                try {
                    const res = await api('GET', `/api/conversations/${id}/draft/similar?` + new URLSearchParams({ summary, project, description: text }));
                    if (id !== this.activeId || !this.draft) return;
                    this.similarKey = key;
                    this.similarIssues = res.issues ?? [];
                } catch (_) {
                    this.similarIssues = [];
                }
            }, 800);
        },
        /** Look-alikes right now (no debounce): the confirmation must not rely on a check that may still be pending. */
        async refreshSimilar() {
            const d = this.draft;
            if (!this.activeId || !d || d.issue_key || !d.project || !String(d.summary ?? '').trim()) return;
            clearTimeout(similarTimer);
            const text = String(d.description ?? '').trim().slice(0, 1500);
            try {
                const res = await api('GET', `/api/conversations/${this.activeId}/draft/similar?` + new URLSearchParams({ summary: d.summary, project: d.project, description: text }));
                this.similarKey = `${d.project}|${d.summary}|${text}`;
                this.similarIssues = res.issues ?? [];
            } catch (_) {
                // only a warning: if it cannot be checked, creating is still possible
            }
        },
        /** Look-alikes whose title is (nearly) the same: creating needs an explicit "this is not a duplicate". */
        get duplicates() {
            return this.draft?.issue_key ? [] : this.similarIssues.filter((i) => i.duplicate);
        },
        setField(field, value) {
            if (!this.draft) return;
            this.draft[field] = value;
            this.pending.add(field);
            if (field === 'project') this.loadMeta(value);
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => this.saveDraft(), field === 'summary' || field === 'description' ? 600 : 100);
        },
        setCustom(key, value) {
            this.draft.custom_fields = { ...this.draft.custom_fields, [key]: value };
            this.pending.add('custom_fields');
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => this.saveDraft(), 100);
        },
        addLabel(raw) {
            const label = raw.trim().replace(/,$/, '');
            if (!label || this.draft.labels.includes(label)) return;
            this.setField('labels', [...this.draft.labels, label]);
        },
        removeLabel(label) {
            this.setField('labels', this.draft.labels.filter((l) => l !== label));
        },
        acceptSuggestion(field) {
            const v = this.draft.suggestions?.[field];
            if (v) this.setField(field, v);
        },
        async saveDraft() {
            if (!this.draft || !this.activeId || this.pending.size === 0) return;
            const fields = [...this.pending];
            const payload = Object.fromEntries(fields.map((f) => [f, this.draft[f]]));
            try {
                const res = await api('PATCH', `/api/conversations/${this.activeId}/draft`, payload);
                const server = res.data ?? res;
                fields.forEach((f) => this.pending.delete(f));
                for (const [k, v] of Object.entries(server)) {
                    if (!this.pending.has(k)) this.draft[k] = v;
                }
            } catch (e) {
                fields.forEach((f) => this.pending.delete(f));
                this.notify(e.message, 'error');
                try {
                    const data = await api('GET', `/api/conversations/${this.activeId}`);
                    this.draft = data.draft;
                } catch (_) {}
            }
        },
        async flushDraft() {
            clearTimeout(saveTimer);
            await this.saveDraft();
        },
        focusField(field) {
            const el = document.getElementById(`field-${field}`);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => el.focus(), 250);
            }
        },

        // ---- simulated / real creation ------------------------------------
        async openConfirm() {
            await this.flushDraft();
            if (!this.valid) return;
            await this.refreshSimilar();
            this.dupAck = false;
            this.simulateError = false;
            this.confirmOpen = true;
        },
        async confirmCreate() {
            this.confirmOpen = false;
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/draft/create`, {
                    confirmed: true,
                    simulate_error: this.demo && this.simulateError,
                });
                this.draft = res.draft;
                this.messages.push(res.message);
                this.syncConversation(res.conversation);
                setTimeout(() => this.scrollBottom(), 40);
            } catch (e) {
                if (e.data?.draft) this.draft = e.data.draft;
                else if (this.draft) this.draft.error_message = e.message;
                this.notify(e.message, 'error');
            } finally {
                this.creating = false;
            }
        },

        // ---- issues browser -----------------------------------------------
        async openIssues(pick = false, fresh = false) {
            this.issuePickMode = pick === true;
            this.editFromMine = false;
            this.issuesOpen = true;
            this.issueEdit = null;
            this.sidebarOpen = false;
            this.issuesLoading = true; // while the catalog loads the list is not empty, it is not here yet
            try {
                await this.ensureCatalog();
            } finally {
                await this.searchIssues(!fresh && this.issueResults.length ? this.issuePage : 1);
            }
        },
        // ---- chat context: the issue this conversation is about ----------------
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
        issueUrl(key) {
            return `${String(this.trackerUrl ?? '').replace(/\/$/, '')}/issue/${key}`;
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
        // ---- an issue key typed in the box: offer to attach it to the message -------
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
        },
        syncConversation(conv) {
            const c = this.conversations.find((x) => x.id === conv?.id);
            if (c) Object.assign(c, conv);
        },
        /** Distinct filter choices; once a project is picked they come from that project only. */
        get issueFilterOptions() {
            return this.filterOptionsFor(this.issueFilters.project);
        },
        /** The values the filters offer: those of the chosen project, or of all of them. */
        filterOptionsFor(project) {
            const c = this.catalog ?? { projects: [], byProject: {} };
            const metas = project ? [c.byProject?.[project]].filter(Boolean) : Object.values(c.byProject ?? {});
            const uniq = (key) => [...new Set(metas.flatMap((m) => m[key] ?? []))];
            const people = new Map();
            metas.flatMap((m) => m.assignees ?? []).forEach((a) => people.set(a.value, { label: a.label, avatar: a.avatar ?? null }));

            return {
                projects: c.projects ?? [],
                types: uniq('types'),
                priorities: uniq('priorities'),
                states: uniq('states'),
                assignees: [...people].map(([value, a]) => ({ value, label: a.label, avatar: a.avatar })).sort((x, y) => x.label.localeCompare(y.label)),
            };
        },
        get issueFiltersActive() {
            return Object.values(this.issueFilters).some((v) => v !== '');
        },
        get issueTotalPages() {
            return this.issueTotal === null ? null : Math.max(1, Math.ceil(this.issueTotal / this.issuePerPage));
        },
        /** Page buttons: 1 … current-1 current current+1 … last (or just prev/next while the total is unknown). */
        get issuePageButtons() {
            const last = this.issueTotalPages;
            if (!last) return [];
            const set = new Set([1, last, this.issuePage - 1, this.issuePage, this.issuePage + 1]);
            const pages = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

            return pages.flatMap((n, i) => (i > 0 && n - pages[i - 1] > 1 ? ['…', n] : [n]));
        },
        get issueRange() {
            if (!this.issueResults.length) return null;
            const from = (this.issuePage - 1) * this.issuePerPage + 1;

            return { from, to: from + this.issueResults.length - 1 };
        },
        async searchIssues(page = 1, quiet = false) {
            clearTimeout(issueTimer);
            const seq = ++issueSearchSeq; // only the latest search may fill the list (a slower, older one must not overwrite it)
            if (!quiet) this.issuesLoading = true;
            const params = new URLSearchParams({ page, per_page: this.issuePerPage });
            Object.entries(this.issueFilters).forEach(([k, v]) => v !== '' && params.set(k, v));
            try {
                const res = await api('GET', `/api/youtrack/issues?${params}`);
                if (seq !== issueSearchSeq) return;
                this.issueResults = res.issues;
                this.issueProjectTotals = res.project_totals ?? {};
                this.issueProjectRanges = res.project_ranges ?? {};
                this.issueHasMore = res.has_more;
                this.issueTotal = res.total;
                this.issuePage = res.page;
            } catch (e) {
                if (!quiet) this.notify(e.message, 'error');
            } finally {
                if (seq === issueSearchSeq) this.issuesLoading = false; // the latest search ends the loading, quiet or not
            }
        },
        /** Search as the user types (after a short pause), without needing Enter. */
        searchIssuesSoon() {
            clearTimeout(issueTimer);
            this.issuesLoading = true;
            issueTimer = setTimeout(() => this.searchIssues(1), 350);
        },
        applyIssueFilters() {
            return this.searchIssues(1);
        },
        resetIssueFilters() {
            this.issueFilters = { q: '', project: '', type: '', priority: '', state: '', assignee: '' };

            return this.searchIssues(1);
        },
        /** Changing the project can invalidate the other choices (their lists are per project). */
        onIssueProjectChange() {
            const o = this.issueFilterOptions;
            if (!o.types.includes(this.issueFilters.type)) this.issueFilters.type = '';
            if (!o.priorities.includes(this.issueFilters.priority)) this.issueFilters.priority = '';
            if (!o.states.includes(this.issueFilters.state)) this.issueFilters.state = '';
            if (!o.assignees.some((a) => a.value === this.issueFilters.assignee)) this.issueFilters.assignee = '';

            return this.searchIssues(1);
        },
        goToIssuePage(n) {
            if (n < 1 || (this.issueTotalPages && n > this.issueTotalPages) || (!this.issueTotalPages && n > this.issuePage && !this.issueHasMore)) return;

            return this.searchIssues(n);
        },
        setIssuePerPage(n) {
            this.issuePerPage = Number(n);

            return this.searchIssues(1);
        },
        // ---- your issues -----------------------------------------------------
        async openMine({ push = true } = {}) {
            this.stopVoice(true);
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.page = 'mine';
            this.sidebarOpen = false;
            if (push && location.pathname !== '/issues') history.pushState({}, '', '/issues');
            this.mineLoading = true;
            this.ensureCatalog(); // the filters' values
            api('GET', '/api/youtrack/mine/summary').then((s) => (this.mineSummary = s)).catch(() => {});
            await this.searchMine(1);
        },
        async searchMine(page = 1) {
            clearTimeout(mineTimer);
            const seq = ++mineSeq;
            this.mineLoading = true;
            const params = new URLSearchParams({ scope: this.mineScope, status: this.mineStatus, page, per_page: this.minePerPage });
            Object.entries(this.mineFilters).forEach(([k, v]) => v !== '' && params.set(k, v));
            if (this.mineQ.trim()) params.set('q', this.mineQ.trim());
            try {
                const res = await api('GET', `/api/youtrack/mine?${params}`);
                if (seq !== mineSeq) return;
                this.mineResults = res.issues;
                this.mineProjectTotals = res.project_totals ?? {};
                this.mineProjectRanges = res.project_ranges ?? {};
                this.mineHasMore = res.has_more;
                this.mineTotal = res.total;
                this.minePage = res.page;
            } catch (e) {
                if (seq === mineSeq) {
                    this.mineResults = [];
                    this.notify(e.message, 'error');
                }
            } finally {
                if (seq === mineSeq) this.mineLoading = false;
            }
        },
        /** The issues of the page, one group per project (the server sorts by project, so each project is in one place). */
        get mineGroups() {
            return this.groupByProject(this.mineResults, this.mineProjectTotals, this.mineProjectRanges);
        },
        get issueGroups() {
            return this.groupByProject(this.issueResults, this.issueProjectTotals, this.issueProjectRanges);
        },
        groupByProject(list, totals = {}, ranges = {}) {
            const groups = new Map();
            for (const i of list) {
                const key = i.project ?? '';
                if (!groups.has(key)) {
                    const p = (this.catalog?.projects ?? []).find((x) => x.key === key);
                    // the project's own icon (initials underneath while it loads or when it has none)
                    groups.set(key, { key, name: i.project_name || key, icon: this.projectOption(p ?? { key, name: key }), items: [] });
                }
                groups.get(key).items.push(i);
            }

            // a count of its own for each project: which of ITS issues are on this page, and how many it has: "0-11 (28 issues)", next page "11-22 (28 issues)"
            return [...groups.values()].map((g) => {
                const r = ranges[g.key];
                const total = totals[g.key] != null ? `(${this.t('mine.total', { n: totals[g.key] })})` : '';

                return { ...g, count: [r ? `${r[0]}-${r[1]}` : '', total].filter(Boolean).join(' ') };
            });
        },
        get mineFilterOptions() {
            return this.filterOptionsFor(this.mineFilters.project);
        },
        get mineFiltersActive() {
            return Object.values(this.mineFilters).some((v) => v !== '') || this.mineQ.trim() !== '';
        },
        /** "0/48 (123 issues)", then "48/96 (123 issues)": where the page starts and ends, and how many there are in all. */
        rangeLabel(page, perPage, shown, total) {
            const from = (page - 1) * perPage;
            const range = `${from}-${from + shown}`;

            return total === null ? range : `${range} (${this.t('mine.total', { n: total })})`;
        },
        get mineRangeLabel() {
            return this.rangeLabel(this.minePage, this.minePerPage, this.mineResults.length, this.mineTotal);
        },
        get issuesRangeLabel() {
            return this.rangeLabel(this.issuePage, this.issuePerPage, this.issueResults.length, this.issueTotal);
        },
        /** Page buttons: 1 … current-1 current current+1 … last. */
        get minePageButtons() {
            const last = this.mineTotalPages;
            if (!last) return [];
            const set = new Set([1, last, this.minePage - 1, this.minePage, this.minePage + 1]);
            const pages = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

            return pages.flatMap((n, i) => (i > 0 && n - pages[i - 1] > 1 ? ['…', n] : [n]));
        },
        goToMinePage(n) {
            if (n < 1 || n === this.minePage || (this.mineTotalPages && n > this.mineTotalPages) || (!this.mineTotalPages && n > this.minePage && !this.mineHasMore)) return;

            return this.searchMine(n);
        },
        get mineTotalPages() {
            return this.mineTotal === null ? null : Math.max(1, Math.ceil(this.mineTotal / this.minePerPage));
        },
        setMineFilter(key, value) {
            this.mineFilters[key] = value || '';
            if (key === 'project') {
                const o = this.mineFilterOptions;
                if (!o.types.includes(this.mineFilters.type)) this.mineFilters.type = '';
                if (!o.priorities.includes(this.mineFilters.priority)) this.mineFilters.priority = '';
                if (!o.states.includes(this.mineFilters.state)) this.mineFilters.state = '';
            }

            return this.searchMine(1);
        },
        resetMineFilters() {
            this.mineFilters = { project: '', type: '', priority: '', state: '' };
            this.mineQ = '';

            return this.searchMine(1);
        },
        setMinePerPage(n) {
            this.minePerPage = Number(n);

            return this.searchMine(1);
        },
        searchMineSoon() {
            clearTimeout(mineTimer);
            this.mineLoading = true;
            mineTimer = setTimeout(() => this.searchMine(1), 350);
        },
        isMineSelected(id) {
            return this.mineSelected.some((i) => i.id === id);
        },
        toggleMineSelect(issue) {
            if (this.isMineSelected(issue.id)) {
                this.mineSelected = this.mineSelected.filter((i) => i.id !== issue.id);
            } else if (this.mineSelected.length >= MAX_ISSUES) {
                this.notify(this.t('errors.too_many_issues', { max: MAX_ISSUES }), 'error');
            } else {
                this.mineSelected = [...this.mineSelected, issue];
            }
        },
        toggleMineSelectMode() {
            this.mineSelectMode = !this.mineSelectMode;
            if (!this.mineSelectMode) this.clearMineSelection();
        },
        clearMineSelection() {
            this.mineSelected = [];
            this.mineText = '';
        },
        /** A new chat with the ticked issues attached; with a message, it is sent right away. */
        startChatFromMine() {
            const issues = [...this.mineSelected];
            const text = this.mineText.trim();
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.newChat(); // goes to the chat, empty
            issues.forEach((i) => this.attachIssue(i));
            if (text) {
                this.composer.text = text;
                this.send();
            }
        },
        /** The page of all the existing issues (/issues/all): the same kind of page as "my issues", without the account and the numbers. */
        async openIssuesPage({ push = true } = {}) {
            this.stopVoice(true);
            this.mineSelectMode = false;
            this.clearMineSelection();
            this.page = 'all';
            this.sidebarOpen = false;
            if (push && location.pathname !== '/issues/all') history.pushState({}, '', '/issues/all');
            this.ensureCatalog(); // the filters' values
            await this.searchIssues(this.issueResults.length ? this.issuePage : 1);
        },
        /** One of the number tiles at the top: shows exactly those issues. */
        showMine(scope, status) {
            this.mineScope = scope;
            this.mineStatus = status;

            return this.searchMine(1);
        },
        /** Opens an issue of the list in the editing window. */
        /** Opens an issue of a page's list in the editing window (closing it returns to the page). */
        editMine(issue) {
            this.editFromMine = true;
            this.issuePickMode = false;
            this.issuesOpen = true;

            return this.editIssue(issue);
        },
        /** The other custom fields of the open issue that cannot be edited here (people, dates, texts, periods…), as [name, value]. */
        get issueEditExtraFields() {
            const editable = (this.issueMeta?.custom ?? []).map((c) => c.key);

            return Object.entries(this.issueEdit?.custom ?? {}).filter(([k]) => !editable.includes(k));
        },
        /** "12 Oct 2026" in the language of the app. */
        shortDate(iso) {
            const d = new Date(iso);

            return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(this.locale === 'pt' ? 'pt-PT' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        },
        /** Closes the edit window (Back, or after saving); when it came from "your issues" the window goes with it. */
        closeIssueEdit() {
            this.issueEdit = null;
            if (this.editFromMine) {
                this.editFromMine = false;
                this.issuesOpen = false;
                if (this.page === 'mine') this.searchMine(this.minePage);
                else if (this.page === 'all') this.searchIssues(this.issuePage);
            }
        },
        async editIssue(issue) {
            this.issueEdit = { ...issue, custom: { ...(issue.custom ?? {}) }, original: { ...issue, custom: { ...(issue.custom ?? {}) } } };
            this.issueMeta = null;
            try {
                this.issueMeta = await api('GET', `/api/youtrack/projects/${encodeURIComponent(issue.project)}/meta`);
                // the issue carries the assignee's name; the form works with the login the list of people is keyed by
                const person = (this.issueMeta.assignees ?? []).find((a) => a.label === issue.assignee);
                this.issueEdit.assigneeValue = person?.value ?? null;
                this.issueEdit.originalAssignee = person?.value ?? null;
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        async saveIssue() {
            const i = this.issueEdit;
            // the project's own enum fields that were changed (the others are shown, not edited)
            const changedCustom = Object.fromEntries((this.issueMeta?.custom ?? []).filter((c) => i.custom?.[c.key] && i.custom[c.key] !== i.original.custom?.[c.key]).map((c) => [c.key, i.custom[c.key]]));
            this.issueSaving = true;
            try {
                const updated = await api('PATCH', `/api/youtrack/issues/${encodeURIComponent(i.id)}`, {
                    summary: i.summary,
                    description: i.description ?? '',
                    type: i.type,
                    priority: i.priority,
                    // only what was changed: a field the user did not touch is left exactly as it is in YouTrack
                    ...(i.state && i.state !== i.original.state ? { state: i.state } : {}),
                    ...(i.assigneeValue && i.assigneeValue !== i.originalAssignee ? { assignee: i.assigneeValue } : {}),
                    ...(i.subsystem && i.subsystem !== i.original.subsystem ? { subsystem: i.subsystem } : {}),
                    ...(Object.keys(changedCustom).length ? { custom_fields: changedCustom } : {}),
                });
                this.issueResults = this.issueResults.map((r) => (r.id === updated.id ? updated : r));
                this.mineResults = this.mineResults.map((r) => (r.id === updated.id ? updated : r));
                this.closeIssueEdit();
                this.notify(this.demo ? this.t('issues.saved_demo') : this.t('issues.saved'), 'success');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.issueSaving = false;
            }
        },
    };
}

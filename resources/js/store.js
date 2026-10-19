import { api } from './api';
import { renderText, formatSize } from './render';
import { streamChat } from './chat';

const REQUIRED = ['summary', 'description', 'project', 'type', 'priority'];
const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'md', 'log', 'json', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'php', 'js', 'ts', 'css', 'html', 'vue', 'sql', 'yml', 'yaml'];
const MAX_FILES = 10;
const MAX_BYTES = 10 * 1024 * 1024;

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
        profileMenu: false,
        confirmOpen: false,
        issuesOpen: false,
        toast: null,
        dragging: false,
        renamingId: null,
        renameValue: '',
        deletingId: null,
        selectMode: false,
        selectedIds: [],
        confirmBulk: false,
        bulkDeleting: false,

        // ---- conversation state ----------------------------------------
        activeId: null,
        messages: [],
        draft: null,
        loadingConversation: false,
        processing: false,
        streaming: false,
        draftEnabled: true,
        catalog: null,
        assigneeDeclined: new Set(),
        creating: false,
        creatingKind: 'create',
        advancedOpen: false,
        cardTab: 'edit',
        simulateError: false,
        pending: new Set(),
        projects: null,
        metaByProject: {},

        // ---- composer ----------------------------------------------------
        composer: { text: '', files: [] },
        // dictation through the browser's speech recognition (Chrome/Edge/Safari); hidden where unsupported
        voice: { supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition), listening: false },

        // ---- issues browser ---------------------------------------------
        issueFilters: { q: '', project: '', type: '', priority: '', state: '', assignee: '' },
        issuePage: 1,
        issuePerPage: 25,
        issueTotal: null,
        issueResults: [],
        issuesLoading: false,
        issueHasMore: false,
        issueEdit: null,
        issueMeta: null,
        issueSaving: false,

        // ================================================================
        init() {
            try {
                this.sidebarCollapsed = localStorage.getItem('im.sidebar') === '1';
            } catch (e) {}
            document.documentElement.lang = this.locale;
            window.addEventListener('keydown', (e) => e.key === 'Escape' && this.selectMode && !this.confirmBulk && this.cancelSelect());
            window.addEventListener('popstate', () => this.routeFromLocation());
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
            if (boot.initialId) this.openConversation(boot.initialId, { push: false });
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
        render: renderText,
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
            for (const c of this.conversations) {
                const t = startOfDay(new Date(c.updated_at ?? Date.now()));
                groups[t >= today ? 'today' : t >= today - day ? 'yesterday' : t >= today - 7 * day ? 'week' : 'older'].push(c);
            }

            return Object.entries(groups).filter(([, items]) => items.length).map(([key, items]) => ({ key, items }));
        },
        get activeConversation() {
            return this.conversations.find((c) => c.id === this.activeId) ?? null;
        },
        get title() {
            return this.activeConversation?.title || this.t('app.name');
        },
        routeFromLocation() {
            const m = location.pathname.match(/^\/c\/(\d+)/);
            if (m) this.openConversation(Number(m[1]), { push: false });
            else this.resetChat();
        },
        resetChat() {
            this.activeId = null;
            this.messages = [];
            this.draft = null;
            this.advancedOpen = false;
            this.cardTab = 'edit';
            this.pending.clear();
        },
        newChat() {
            this.stopVoice();
            this.resetChat();
            this.sidebarOpen = false;
            this.clearComposer();
            history.pushState({}, '', '/');
            this.$nextTick?.(() => this.focusComposer());
            setTimeout(() => this.focusComposer(), 30);
        },
        async openConversation(id, { push = true } = {}) {
            if (this.loadingConversation) return;
            this.sidebarOpen = false;
            this.loadingConversation = true;
            try {
                const data = await api('GET', `/api/conversations/${id}`);
                this.activeId = data.conversation.id;
                this.messages = data.messages;
                this.draft = data.draft;
                this.advancedOpen = false;
                this.cardTab = 'edit';
                this.pending.clear();
                if (!this.conversations.some((c) => c.id === id)) this.conversations.unshift(data.conversation);
                if (push) history.pushState({}, '', `/c/${id}`);
                if (this.draft?.project) this.loadMeta(this.draft.project);
                this.loadProjects();
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
        // ---- multi-select delete -------------------------------------------
        startSelect() {
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
            return !this.processing && (c.text.trim() !== '' || c.files.length > 0);
        },
        /** Conversation so far as plain text turns for the chat API (attachments of past turns are only named). */
        historyForApi() {
            return this.messages
                .filter((m) => (m.role === 'user' || m.kind === 'text' || m.kind === 'question') && (m.body || m.attachments?.length))
                .map((m) => {
                    const names = (m.attachments ?? []).map((a) => a.name);
                    const note = names.length ? `\n\n[${this.t('chat.previous_attachments')}: ${names.join(', ')}]` : '';

                    return { role: m.role, content: `${m.body ?? ''}${note}`.trim() };
                })
                .filter((m) => m.content !== '');
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
        peopleFor(catalog, project) {
            return catalog?.byProject?.[project]?.assignees ?? [];
        },
        async send(textOverride = null) {
            const fromComposer = textOverride === null;
            if (this.processing) return;
            const body = (fromComposer ? this.composer.text : textOverride).trim();
            const files = fromComposer ? [...this.composer.files] : [];
            if (!body && !files.length) return;

            this.stopVoice();
            this.processing = true;
            this.streaming = false;
            let temp = null;
            let live = null;
            try {
                if (!this.activeId) {
                    const c = await api('POST', '/api/conversations');
                    this.conversations.unshift(c);
                    this.activeId = c.id;
                    history.pushState({}, '', `/c/${c.id}`);
                    this.loadProjects();
                }

                const turns = this.historyForApi();
                // "no / nobody" after the app asked about an assignee: stop asking in this conversation
                const lastAssistant = [...this.messages].reverse().find((m) => m.role === 'assistant');
                if (lastAssistant?.meta?.asked_assignee && /^\s*(n[ãa]o|ningu[ée]m|no|nobody|none|deixa|leave|sem )/i.test(body)) this.assigneeDeclined.add(this.activeId);
                const sentBody = body || (files.length ? this.t('chat.attachments_only') : '');
                turns.push({ role: 'user', content: sentBody });
                const catalog = await this.ensureCatalog();

                temp = {
                    id: `tmp-${++uid}`,
                    role: 'user',
                    kind: 'text',
                    body,
                    meta: {},
                    attachments: [
                        ...files.map((f) => ({ id: f.id, kind: f.kind, name: f.name, size: f.size, url: f.preview })),
                    ],
                };
                this.messages.push(temp);
                if (fromComposer) this.clearComposer(false);
                setTimeout(() => this.scrollBottom(), 30);

                live = { id: `tmp-${++uid}`, role: 'assistant', kind: 'text', body: '', meta: {}, attachments: [] };
                let text = '';
                const { patch: draftPatch, created, updated } = await streamChat(
                    {
                        payload: {
                            messages: turns,
                            draft_enabled: this.draftEnabled,
                            draft: this.draft ? this.draftForApi() : null,
                            catalog,
                        },
                        files: files.map((f) => f.file),
                    },
                    {
                        onText: (t) => {
                            text += t;
                            if (!this.streaming) {
                                this.streaming = true;
                                this.messages.push(live);
                            }
                            live.body = text;
                            this.scrollBottomIfNear();
                        },
                    },
                );

                // draft mode off: Claude may call create_issue when the user explicitly asks for an issue
                const direct = !this.draftEnabled && !!created;
                const patch = direct ? created : draftPatch;

                if (patch?.message && typeof patch.message === 'string') {
                    text = patch.message;
                    delete patch.message;
                }
                if (!text.trim() && updated?.message) text = updated.message;
                if (!text.trim() && updated) text = this.t('chat.updating_issue');
                if (!text.trim() && patch) text = this.t(direct ? 'chat.creating_issue' : 'chat.draft_updated');

                // Always offer an assignee while the draft has none (done by the app so it never depends on the model)
                let askedAssignee = false;
                const people = this.peopleFor(catalog, patch?.project ?? this.draft?.project);
                const named = people.some((a) => text.toLowerCase().includes(a.label.split(' ')[0].toLowerCase()));
                const hasAssignee = (patch?.assignee && people.some((a) => a.value === patch.assignee)) || this.draft?.assignee;
                if (this.draftEnabled && patch && !hasAssignee && !named && !this.assigneeDeclined.has(this.activeId) && people.length) {
                    text = `${text.trim()}\n\n${this.t('chat.ask_assignee', { names: people.map((a) => a.label).join(', ') })}`;
                    askedAssignee = true;
                } else if (named && text.includes('?') && !hasAssignee) askedAssignee = true;

                if (!text.trim()) throw new Error(this.t('errors.chat_failed'));

                const fd = new FormData();
                fd.append('body', body);
                fd.append('assistant', JSON.stringify({ text, draft_patch: this.draftEnabled || direct ? patch : null, direct, asked_assignee: askedAssignee }));
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
                    this.composer.text = body;
                    this.composer.files = files;
                }
                this.notify(e.message || this.t('errors.generic'), 'error');
            } finally {
                this.processing = false;
                this.streaming = false;
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
        toggleVoice() {
            return this.voice.listening ? this.stopVoice() : this.startVoice();
        },
        startVoice() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return this.notify(this.t('errors.voice_unsupported'), 'error');
            if (this.processing || this.voice.listening) return;

            const rec = new SR();
            rec.lang = this.locale === 'pt' ? 'pt-PT' : 'en-US';
            rec.continuous = true;
            rec.interimResults = true;
            rec.maxAlternatives = 1;

            const base = this.composer.text.trim();
            let finals = '';
            rec.onresult = (e) => {
                let interim = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const r = e.results[i];
                    if (r.isFinal) finals += r[0].transcript;
                    else interim += r[0].transcript;
                }
                this.composer.text = [base, `${finals}${interim}`.trim()].filter(Boolean).join(' ');
            };
            rec.onerror = (e) => {
                if (e.error === 'no-speech' || e.error === 'aborted') return;
                const key = { 'not-allowed': 'voice_denied', 'service-not-allowed': 'voice_denied', 'audio-capture': 'voice_no_mic' }[e.error] ?? 'voice_failed';
                this.notify(this.t(`errors.${key}`), 'error');
            };
            rec.onend = () => {
                this.voice.listening = false;
                this.voiceRecognition = null;
            };

            try {
                rec.start();
                this.voiceRecognition = rec;
                this.voice.listening = true;
                this.focusComposer();
            } catch (e) {
                this.notify(this.t('errors.voice_failed'), 'error');
            }
        },
        stopVoice() {
            try {
                this.voiceRecognition?.stop();
            } catch (e) {}
            this.voice.listening = false;
        },
        onComposerKey(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                this.send();
            }
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
                if (!ALLOWED_EXT.includes(ext)) {
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
            if (revoke) this.composer.files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
            this.composer = { text: '', files: [] };
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
        async openIssues() {
            this.issuesOpen = true;
            this.issueEdit = null;
            this.sidebarOpen = false;
            await this.ensureCatalog();
            await this.searchIssues(this.issueResults.length ? this.issuePage : 1);
        },
        /** Distinct filter choices; once a project is picked they come from that project only. */
        get issueFilterOptions() {
            const c = this.catalog ?? { projects: [], byProject: {} };
            const metas = this.issueFilters.project ? [c.byProject?.[this.issueFilters.project]].filter(Boolean) : Object.values(c.byProject ?? {});
            const uniq = (key) => [...new Set(metas.flatMap((m) => m[key] ?? []))];
            const people = new Map();
            metas.flatMap((m) => m.assignees ?? []).forEach((a) => people.set(a.value, a.label));

            return {
                projects: c.projects ?? [],
                types: uniq('types'),
                priorities: uniq('priorities'),
                states: uniq('states'),
                assignees: [...people].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label)),
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
        async searchIssues(page = 1) {
            this.issuesLoading = true;
            const params = new URLSearchParams({ page, per_page: this.issuePerPage });
            Object.entries(this.issueFilters).forEach(([k, v]) => v !== '' && params.set(k, v));
            try {
                const res = await api('GET', `/api/youtrack/issues?${params}`);
                this.issueResults = res.issues;
                this.issueHasMore = res.has_more;
                this.issueTotal = res.total;
                this.issuePage = res.page;
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.issuesLoading = false;
            }
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
        async editIssue(issue) {
            this.issueEdit = { ...issue };
            this.issueMeta = null;
            try {
                this.issueMeta = await api('GET', `/api/youtrack/projects/${encodeURIComponent(issue.project)}/meta`);
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        async saveIssue() {
            const i = this.issueEdit;
            this.issueSaving = true;
            try {
                const updated = await api('PATCH', `/api/youtrack/issues/${encodeURIComponent(i.id)}`, {
                    summary: i.summary,
                    description: i.description ?? '',
                    type: i.type,
                    priority: i.priority,
                });
                this.issueResults = this.issueResults.map((r) => (r.id === updated.id ? updated : r));
                this.issueEdit = null;
                this.notify(this.demo ? this.t('issues.saved_demo') : this.t('issues.saved'), 'success');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.issueSaving = false;
            }
        },
    };
}

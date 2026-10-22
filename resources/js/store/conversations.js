// The list of conversations: open, rename, search, select and delete.
import { api } from '../api';

let pressTimer;
let pressed = false; // a long press on a conversation just started the selection: its click must not also open it

export function conversationsSlice(boot) {
    return {
        conversations: boot.conversations ?? [],
        renamingId: null,
        renameValue: '',
        deletingId: null,
        selectMode: false,
        historySearching: false, // the title search box is open
        historyQuery: '',
        selectedIds: [],
        confirmBulk: false,
        bulkDeleting: false,
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
        routeFromLocation() {
            const m = location.pathname.match(/^\/c\/(\d+)/);
            if (location.pathname === '/issues') this.openMine({ push: false });
            else if (location.pathname === '/issues/all') this.openIssuesPage({ push: false });
            else if (location.pathname === '/analytics') this.openAnalytics({ push: false });
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
        startHistorySearch() {
            this.historySearching = true;
            setTimeout(() => document.getElementById('history-search')?.focus(), 30);
        },
        stopHistorySearch() {
            this.historySearching = false;
            this.historyQuery = '';
        },
        // Shift+click on a conversation (or a long press on a touch screen) starts the selection for deleting several at once.
        onConversationClick(e, c) {
            if (pressed) {
                pressed = false; // the long press already did its job

                return;
            }
            if (e.shiftKey && !this.selectMode) this.startSelect();
            if (this.selectMode) this.toggleSelect(c.id);
            else this.openConversation(c.id);
        },
        conversationPressStart(e, c) {
            clearTimeout(pressTimer);
            pressed = false;
            if (e.pointerType !== 'touch' || this.selectMode) return;
            pressTimer = setTimeout(() => {
                pressed = true;
                this.startSelect();
                this.toggleSelect(c.id);
                navigator.vibrate?.(10);
            }, 500);
        },
        conversationPressEnd() {
            clearTimeout(pressTimer);
        },
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
        syncConversation(conv) {
            const c = this.conversations.find((x) => x.id === conv?.id);
            if (c) Object.assign(c, conv);
        },    };
}

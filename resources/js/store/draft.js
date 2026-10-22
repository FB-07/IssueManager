// The issue card (the draft), duplicates and creation in YouTrack.
import { api } from '../api';
import { REQUIRED } from './shared';

let similarTimer;

let saveTimer = null;

export function draftSlice() {
    return {
        confirmOpen: false,
        draft: null,
        similarIssues: [], // recent issues YouTrack is still being asked about: not offered until it answers
        similarKey: null,
        dupAck: false, // the answer has started but nothing new arrives (Claude is looking something up): dots show under the text
        draftEnabled: true,
        creating: false,
        creatingKind: 'create',
        advancedOpen: false,
        cardTab: 'edit',
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
        /** The user asked Claude to comment on an existing issue: the comment is posted in YouTrack and shown in the chat. */
        async applyIssueComment(comment) {
            this.creatingKind = 'comment';
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/issues/comment`, { issue_id: comment.issue_id, text: comment.text, comment_id: comment.comment_id });
                if (res.original) this.putMessage(res.original);
                this.putMessage(res.message);
                this.syncConversation(res.conversation);
                setTimeout(() => this.scrollBottom(), 40);
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.creating = false;
            }
        },
        /** Replaces a card already in the conversation (an edited comment) or appends it. */
        putMessage(message) {
            const i = this.messages.findIndex((m) => m.id === message.id);
            if (i >= 0) this.messages.splice(i, 1, message);
            else this.messages.push(message);
        },
        /** The user rewrites a comment the app posted: the card is edited in place. */
        async saveComment(m) {
            const text = (m.editText ?? '').trim();
            if (!text) return;
            this.creatingKind = 'comment';
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/issues/comment`, { issue_id: m.meta.key, text, comment_id: m.meta.comment_id, in_place: true });
                this.putMessage(res.message);
                this.syncConversation(res.conversation);
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.creating = false;
            }
        },
        /** What the waiting indicator says while the app works in YouTrack. */
        get creatingText() {
            return this.t({ update: 'chat.updating', comment: 'chat.commenting' }[this.creatingKind] ?? 'chat.creating');
        },
        draftForApi() {
            const d = this.draft;

            return { summary: d.summary, description: d.description, project: d.project, type: d.type, priority: d.priority, assignee: d.assignee, labels: d.labels, sprint: d.sprint, subsystem: d.subsystem, custom_fields: d.custom_fields, issue_key: d.issue_key, suggestions: d.suggestions };
        },
        get missing() {
            return this.draft ? REQUIRED.filter((f) => !String(this.draft[f] ?? '').trim()) : [];
        },
        get valid() {
            return this.draft && this.missing.length === 0;
        },
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
        async openConfirm() {
            await this.flushDraft();
            if (!this.valid) return;
            await this.refreshSimilar();
            this.dupAck = false;
            this.confirmOpen = true;
        },
        async confirmCreate() {
            this.confirmOpen = false;
            this.creating = true;
            try {
                const res = await api('POST', `/api/conversations/${this.activeId}/draft/create`, {
                    confirmed: true,
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
        },    };
}

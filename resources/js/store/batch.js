// Several issues proposed from one text, reviewed one by one.
import { api } from '../api';
import { REQUIRED } from './shared';

let batchSimilarTimer;
const batchSaveTimers = new Map();
const batchScopes = new Map();
const EMPTY_META = { types: [], priorities: [], subsystems: [], sprints: [], assignees: [], custom: [] };

export function batchSlice() {
    return {
        batches: {},
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
        },    };
}

// The chat: messages, composer, attachments and sending.
import { api } from '../api';
import { streamChat } from '../chat';
import { MAX_FILES, MAX_BYTES, nextUid } from './shared';

const BLOCKED_EXT = ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'dll', 'jar', 'apk', 'dmg', 'app', 'pif', 'vbs'];
const QUOTE_MAX = 1500;
const quoteBlock = (t) => t.split('\n').map((l) => `> ${l}`.trimEnd()).join('\n');
const LONG_MESSAGES = 30; // a conversation this long (or this big) gets the notice
const LONG_CHARS = 60000;
let stallTimer;
let elapsedTimer;
let answerAbort = null; // aborts the answer being streamed (the stop button)

export function chatSlice() {
    return {
 // told to the user when files are dragged in
        longDismissed: [], // conversations whose "too long" notice was closed
        showJump: false,
        activeId: null,
        messages: [],
        loadingConversation: false,
        processing: false,
        queue: [],
        streaming: false,
        elapsed: 0, // seconds since the message was sent, while the answer is being prepared
        stalled: false,
        assigneeDeclined: new Set(),
        pending: new Set(),
        composer: { text: '', files: [], quote: '' }, // quote: a passage of an earlier answer this message replies to
        selection: null, // issues attached to the message being written, like its files: [{ key, summary, state, url }]
        attachMenu: false,
        get lastCardId() {
            const card = [...this.messages].reverse().find((m) => m.meta?.card);
            if (card) return card.id;

            // a direct creation (draft mode off) that failed: bring the editable card back so it can be fixed and retried
            return this.draft?.status === 'error' ? ([...this.messages].reverse().find((m) => m.role === 'assistant' && m.kind === 'text')?.id ?? null) : null;
        },
        /** Shows the "back down" arrow when the chat is scrolled up from its end. */
        onChatScroll(el) {
            this.showJump = el.scrollHeight - el.scrollTop - el.clientHeight > 240;
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
            const turns = this.messages
                .filter((m) => (m.role === 'user' || m.kind === 'text' || m.kind === 'question' || m.kind === 'creation-result') && (m.body || m.attachments?.length || this.issuesOf(m).length || m.kind === 'creation-result'))
                .map((m) => {
                    // what the APP did (created, changed or commented on an issue) is shown to the user as a card; the model has to be
                    // told too, or it forgets it happened and even denies it
                    if (m.kind === 'creation-result') return { role: 'assistant', content: this.appNote(m) };
                    const names = (m.attachments ?? []).map((a) => a.name);
                    const note = names.length ? `\n\n[${this.t('chat.previous_attachments')}: ${names.join(', ')}]` : '';

                    return { role: m.role, content: `${m.role === 'user' ? this.issueTag(this.issuesOf(m)) : ''}${m.body ?? ''}${note}`.trim() };
                })
                .filter((m) => m.content !== '');

            // the answer and the note about what the app did are one assistant turn (user messages stay apart: each carries its own issues)
            return turns.reduce((out, m) => {
                if (m.role === 'assistant' && out.at(-1)?.role === 'assistant') out.at(-1).content += `\n\n${m.content}`;
                else out.push({ ...m });

                return out;
            }, []);
        },
        /** One line for the model about a card of the conversation ("[App: comment posted on DEV-54: …]"). */
        appNote(m) {
            const meta = m.meta ?? {};
            const key = meta.key ?? '?';
            if (meta.commented) return `[App: the comment was posted on issue ${key}${meta.comment_id ? ` (comment_id ${meta.comment_id})` : ''}${meta.edited ? ', edited' : ''}: "${meta.comment ?? ''}"]`;
            if (meta.updated) return `[App: issue ${key} was updated in YouTrack${meta.changes?.length ? ` (${meta.changes.join(', ')})` : ''}]`;

            return `[App: issue ${key} was created in YouTrack: "${meta.summary ?? ''}"]`;
        },
        /** The issues that went with a message (attached like its files). */
        issuesOf(m) {
            return m.meta?.contexts ?? (m.meta?.context ? [m.meta.context] : []);
        },
        /** What tells Claude which issues a message carried: `[Contexto: DEV-1, DEV-2] `. */
        issueTag(issues) {
            return issues.length ? `[Contexto: ${issues.map((i) => i.key).join(', ')}] ` : '';
        },
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
                id: `tmp-${nextUid()}`,
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

                live = { id: `tmp-${nextUid()}`, role: 'assistant', kind: 'text', body: '', meta: {}, attachments: [] };
                let text = '';
                const ctl = (answerAbort = new AbortController());
                ctl.timer = setTimeout(() => ctl.abort(new DOMException('timeout', 'TimeoutError')), 330000); // a stalled connection must not leave the chat waiting forever
                const { patch: draftPatch, created, updated, commented, replies: modelReplies, batch, stopped } = await streamChat(
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
                if (!text.trim() && commented?.message) text = commented.message;
                if (!text.trim() && commented) text = this.t('chat.commenting_issue');
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
                if (commented?.issue_id) await this.applyIssueComment(commented);
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
        scrollBottomIfNear() {
            const el = document.getElementById('chat-scroll');
            if (el && el.scrollHeight - el.scrollTop - el.clientHeight < 160) el.scrollTo({ top: el.scrollHeight });
        },
        onComposerKey(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                this.send();
            }
        },
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
                    id: `f-${nextUid()}`,
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
        /** True when the conversation is long enough that each message costs more and Claude may lose the thread. */
        get conversationLong() {
            if (this.page !== 'chat' || !this.activeId || this.longDismissed.includes(this.activeId)) return false;
            const chars = this.messages.reduce((n, m) => n + (m.body?.length ?? 0), 0);

            return this.messages.length >= LONG_MESSAGES || chars >= LONG_CHARS;
        },
        dismissLong() {
            if (this.activeId) this.longDismissed = [...this.longDismissed, this.activeId];
        },    };
}

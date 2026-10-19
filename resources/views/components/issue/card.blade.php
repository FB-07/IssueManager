<div class="anim-rise mt-4 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" x-data="{ labelInput: '' }" aria-label="Issue">

    {{-- header --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent"><x-ui.icon name="file" class="size-[18px]" /></span>
        <div class="min-w-0">
            <h3 class="text-sm font-semibold leading-tight" x-text="s.t('card.title')"></h3>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                <span x-text="s.t('card.revision', { n: s.draft.revision })"></span>
                <span class="text-faint" aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1.5" x-bind:class="s.missing.length ? 'text-warn' : 'text-success'">
                    <span class="size-1.5 rounded-full bg-current"></span>
                    <span x-text="s.t('card.required_progress', { done: 5 - s.missing.length, total: 5 })"></span>
                </span>
                <span class="badge bg-success-soft text-success" x-show="s.draft.status === 'created'" x-text="s.draft.issue_key"></span>
                <span class="badge bg-danger-soft text-danger" x-show="s.draft.status === 'error'" x-text="s.t('card.status.error')"></span>
            </p>
        </div>

        <div class="ml-auto flex rounded-xl border border-line bg-surface-2/60 p-0.5 text-xs" role="tablist">
            <button type="button" role="tab" class="rounded-[10px] px-3 py-1.5 font-medium transition" x-bind:aria-selected="s.cardTab === 'edit'" x-bind:class="s.cardTab === 'edit' ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg'" @click="s.cardTab = 'edit'" x-text="s.t('card.tab_edit')"></button>
            <button type="button" role="tab" class="rounded-[10px] px-3 py-1.5 font-medium transition" x-bind:aria-selected="s.cardTab === 'preview'" x-bind:class="s.cardTab === 'preview' ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg'" @click="s.cardTab = 'preview'" x-text="s.t('card.tab_preview')"></button>
        </div>
    </div>

    {{-- notices --}}
    <div class="space-y-2 px-4 sm:px-5" x-show="s.demo || s.missing.length || s.draft.status === 'error'">
        <p class="flex items-start gap-2 rounded-xl bg-warn-soft px-3.5 py-2.5 text-xs text-warn" x-show="s.demo">
            <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('card.demo_notice')"></span>
        </p>

        <div class="rounded-xl border border-danger/30 bg-danger-soft px-3.5 py-2.5" x-show="s.missing.length" role="alert">
            <p class="flex items-center gap-2 text-xs font-medium text-danger"><x-ui.icon name="alert" class="size-3.5" /><span x-text="s.t('card.missing_title')"></span></p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="f in s.missing" x-bind:key="f">
                    <button type="button" class="chip border-danger/40 bg-surface text-danger transition hover:bg-danger/10" @click="s.cardTab = 'edit'; $nextTick(() => s.focusField(f))" x-text="s.t('fields.' + f)"></button>
                </template>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-3.5 py-3" x-show="s.draft.status === 'error'" role="alert">
            <x-ui.icon name="alert" class="size-4 shrink-0 text-danger" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium text-danger" x-text="s.t('card.error_title')"></p>
                <p class="text-xs text-muted" x-text="s.draft.error_message"></p>
                <p class="text-xs text-muted" x-text="s.t('card.error_kept')"></p>
            </div>
            <button type="button" class="btn" @click="s.openConfirm()" x-bind:disabled="s.creating || !s.valid"><x-ui.icon name="refresh" /><span x-text="s.t('card.retry')"></span></button>
        </div>
    </div>

    {{-- edit --}}
    <div x-show="s.cardTab === 'edit'" class="space-y-5 px-4 pb-5 pt-4 sm:px-5">

        {{-- title + description --}}
        <div class="space-y-4">
            <x-issue.field field="summary" required>
                <input id="field-summary" class="field py-2.5 text-[15px] font-semibold" type="text" maxlength="255" x-bind:class="s.missing.includes('summary') && 'field-invalid'"
                       x-model="s.draft.summary" @input="s.setField('summary', $event.target.value)">
            </x-issue.field>

            <x-issue.field field="description" required>
                <textarea id="field-description" rows="8" class="field resize-y font-mono text-[13px] leading-relaxed" x-bind:class="s.missing.includes('description') && 'field-invalid'"
                          x-model="s.draft.description" @input="s.setField('description', $event.target.value)"></textarea>
            </x-issue.field>
        </div>

        {{-- properties --}}
        <section class="rounded-2xl border border-line bg-surface-2/40 p-4" aria-labelledby="card-props">
            <h4 id="card-props" class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="s.t('card.section_properties')"></h4>
            <div class="grid gap-4 sm:grid-flow-col sm:grid-cols-2 sm:grid-rows-2">
                <x-issue.field field="project" required>
                    <x-ui.select id="field-project" value="s.draft.project" select="s.setField('project', $value || null)"
                        options="(s.projects ?? []).map((p) => s.projectOption(p))" invalid="s.missing.includes('project')" on-open="s.loadProjects()" />
                </x-issue.field>

                <x-issue.field field="assignee">
                    <x-ui.select id="field-assignee" value="s.draft.assignee" select="s.setField('assignee', $value || null)"
                        options="s.meta.assignees.map((a) => s.personOption(a))" empty-label="s.t('card.unassigned')" />
                </x-issue.field>

                <x-issue.field field="priority" required>
                    <x-ui.select id="field-priority" value="s.draft.priority" select="s.setField('priority', $value || null)"
                        options="s.meta.priorities.map((p) => ({ value: p, label: s.lbl('priority', p) }))" invalid="s.missing.includes('priority')" />
                </x-issue.field>
            </div>

            <div class="mt-4">
                <x-issue.field field="type" required>
                    <div class="flex flex-wrap gap-1.5" role="radiogroup" x-bind:aria-label="s.t('fields.type')">
                        <template x-for="(t, i) in s.meta.types" x-bind:key="t">
                            <button type="button" role="radio" x-bind:id="i === 0 ? 'field-type' : null" x-bind:aria-checked="s.draft.type === t"
                                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    x-bind:class="s.draft.type === t ? 'border-transparent bg-accent text-accent-fg' : (s.missing.includes('type') ? 'border-danger/50 text-muted hover:text-fg' : 'border-line bg-surface text-muted hover:border-line-strong hover:text-fg')"
                                    @click="s.setField('type', t)">
                                <span class="size-3.5" x-html="s.icon(['Bug', 'Anomaly'].includes(t) ? 'bug' : ['Feature', 'Improvement'].includes(t) ? 'sparkles' : 'check-circle')"></span>
                                <span x-text="s.lbl('type', t)"></span>
                            </button>
                        </template>
                    </div>
                </x-issue.field>
            </div>
        </section>

        {{-- advanced --}}
        <section class="rounded-2xl border border-line">
            <button type="button" class="flex w-full items-center gap-2 rounded-2xl px-4 py-3 text-left text-sm font-medium transition hover:bg-surface-2/60" @click="s.advancedOpen = !s.advancedOpen" x-bind:aria-expanded="s.advancedOpen">
                <span class="size-4 text-muted transition" x-bind:class="s.advancedOpen && 'rotate-90'" x-html="s.icon('chevron-right')"></span>
                <span x-text="s.t('card.advanced')"></span>
                <span class="ml-auto text-xs font-normal text-faint" x-text="s.t('card.optional')"></span>
            </button>
            <div x-show="s.advancedOpen" x-collapse.duration.200ms x-cloak class="grid gap-4 border-t border-line p-4 sm:grid-cols-2">
                <p class="text-xs text-muted sm:col-span-2" x-show="!s.draft.project" x-text="s.t('card.need_project')"></p>

                <x-issue.field field="sprint" x-show="s.meta.sprints.length">
                    <x-ui.select id="field-sprint" value="s.draft.sprint" select="s.setField('sprint', $value || null)"
                        options="s.meta.sprints.map((o) => ({ value: o, label: s.lbl('sprint', o) }))" empty-label="s.t('card.none')" />
                </x-issue.field>
                <x-issue.field field="subsystem" x-show="s.meta.subsystems.length">
                    <x-ui.select id="field-subsystem" value="s.draft.subsystem" select="s.setField('subsystem', $value || null)"
                        options="s.meta.subsystems.map((o) => ({ value: o, label: s.lbl('subsystem', o) }))" empty-label="s.t('card.none')" />
                </x-issue.field>

                <div>
                    <label for="field-labels" class="label" x-text="s.t('fields.labels')"></label>
                    <div class="field flex flex-wrap items-center gap-1.5 py-1.5">
                        <template x-for="l in s.draft.labels" x-bind:key="l">
                            <span class="chip bg-surface-3 text-fg"><span x-text="l"></span>
                                <button type="button" class="text-muted hover:text-fg" @click="s.removeLabel(l)" x-bind:aria-label="s.t('common.remove') + ' ' + l"><x-ui.icon name="x" class="size-3" /></button>
                            </span>
                        </template>
                        <input id="field-labels" class="min-w-[6rem] flex-1 bg-transparent py-0.5 text-sm placeholder:text-faint focus:outline-none" x-model="labelInput" x-bind:placeholder="s.t('card.add_label')"
                               @keydown.enter.prevent="s.addLabel(labelInput); labelInput = ''" @keydown.comma.prevent="s.addLabel(labelInput); labelInput = ''" @blur="s.addLabel(labelInput); labelInput = ''">
                    </div>
                </div>

                <template x-for="cf in s.meta.custom" x-bind:key="cf.key">
                    <div>
                        <label class="label" x-text="s.lbl('custom', cf.key)"></label>
                        <x-ui.select value="s.draft.custom_fields[cf.key] ?? ''" select="s.setCustom(cf.key, $value || null)"
                            options="cf.values.map((v) => ({ value: v, label: s.lbl('customValue', v) }))" empty-label="s.t('card.none')" label="s.lbl('custom', cf.key)" />
                    </div>
                </template>
            </div>
        </section>

        {{-- attachments that go to the YouTrack issue --}}
        <section x-show="s.conversationAttachments.length" aria-labelledby="card-atts">
            <div class="mb-2.5 flex items-center gap-2">
                <h4 id="card-atts" class="text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="s.t('card.attachments_title')"></h4>
                <span class="badge bg-surface-3 text-muted" x-text="s.t('card.attachments_count', { n: s.pendingAttachmentCount })"></span>
            </div>
            <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                <template x-for="a in s.conversationAttachments" x-bind:key="a.id">
                    <li>
                        <label class="group relative block cursor-pointer overflow-hidden rounded-xl border bg-surface transition"
                               x-bind:class="(a.to_issue || a.uploaded_to) ? 'border-line-strong' : 'border-line opacity-60 hover:opacity-100'">
                            <div class="flex h-20 items-center justify-center bg-surface-2">
                                <template x-if="a.kind === 'image'"><img x-bind:src="a.url" x-bind:alt="a.name" class="size-full object-cover"></template>
                                <template x-if="a.kind !== 'image'"><span class="size-7 text-muted"><x-ui.icon name="file" class="size-full" /></span></template>
                            </div>
                            <input type="checkbox" class="absolute left-2 top-2 size-4 rounded accent-[var(--accent)]" x-bind:checked="a.to_issue || !!a.uploaded_to" x-bind:disabled="!!a.uploaded_to" @change="s.toggleAttachment(a)" x-bind:aria-label="a.name">
                            <div class="px-2.5 py-2">
                                <p class="truncate text-xs font-medium" x-text="a.name"></p>
                                <p class="mt-0.5 flex items-center gap-1.5 text-[11px] text-faint">
                                    <span x-text="s.size(a.size ?? 0)"></span>
                                    <span class="font-medium text-success" x-show="a.uploaded_to" x-text="s.t('card.attachment_sent', { key: a.uploaded_to })"></span>
                                </p>
                            </div>
                        </label>
                    </li>
                </template>
            </ul>
            <p class="mt-2 text-xs text-muted" x-text="s.t('card.attachments_hint')"></p>
        </section>
    </div>

    {{-- preview --}}
    <div x-show="s.cardTab === 'preview'" x-cloak class="space-y-4 px-4 pb-5 pt-4 sm:px-5">
        <h4 class="text-lg font-semibold leading-snug" x-text="s.draft.summary || s.t('card.no_summary')"></h4>
        <dl class="grid gap-x-6 gap-y-2.5 rounded-xl border border-line bg-surface-2/40 p-4 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
            <dt class="text-muted" x-text="s.t('fields.project')"></dt><dd class="font-medium" x-text="s.draft.project || '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.type')"></dt><dd class="font-medium" x-text="s.draft.type ? s.lbl('type', s.draft.type) : '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.priority')"></dt><dd class="font-medium" x-text="s.draft.priority ? s.lbl('priority', s.draft.priority) : '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.assignee')"></dt><dd class="font-medium" x-text="s.draft.assignee ? (s.meta.assignees.find((a) => a.value === s.draft.assignee)?.label ?? s.draft.assignee) : '—'"></dd>
        </dl>
        <div class="flex flex-wrap gap-1.5 text-xs" x-show="s.draft.labels.length || Object.keys(s.draft.custom_fields ?? {}).length">
            <template x-for="(v, k) in (s.draft.custom_fields ?? {})" x-bind:key="k"><span class="chip"><span x-text="s.lbl('custom', k)"></span>: <span x-text="s.lbl('customValue', v)"></span></span></template>
            <template x-for="l in s.draft.labels" x-bind:key="l"><span class="chip"><x-ui.icon name="tag" class="size-3" /><span x-text="l"></span></span></template>
        </div>
        <div class="prose-msg rounded-xl border border-line p-4 text-sm" x-html="s.render(s.draft.description)"></div>
    </div>

    {{-- create / save --}}
    <div class="border-t border-line bg-surface-2/40 px-4 py-4 sm:px-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="flex min-w-0 items-center gap-2 text-xs text-muted">
                <span class="size-3.5 shrink-0" x-bind:class="s.valid ? 'text-success' : 'text-warn'" x-html="s.icon(s.valid ? 'check-circle' : 'alert')"></span>
                <span x-show="!s.valid" x-text="s.t('card.complete_to_create')"></span>
                <span x-show="s.valid" x-text="s.t('card.confirm_needed')"></span>
            </p>
            <button type="button" class="btn btn-primary ml-auto px-4" @click="s.openConfirm()" x-bind:disabled="!s.valid || s.creating || s.processing">
                <span class="size-4" x-bind:class="s.creating && 'anim-spin'" x-html="s.icon(s.creating ? 'loader' : 'check')"></span>
                <span x-text="s.creating ? s.t('card.creating') : (s.draft.status === 'created' ? s.t('card.save_changes', { key: s.draft.issue_key }) : s.t('card.create'))"></span>
            </button>
        </div>
    </div>
</div>

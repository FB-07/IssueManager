@props(['c' => 's', 'batch' => false])
{{-- $c: JS expression of the object holding the draft (the store, or one proposal of a batch carousel) --}}
<div class="anim-rise mt-4 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" x-data="{ labelInput: '' }" @unless($batch) x-effect="s.watchSimilar(s.draft.summary, s.draft.project, s.draft.description)" @endunless aria-label="Issue">

    {{-- header --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent"><x-ui.icon name="file" class="size-[18px]" /></span>
        <div class="min-w-0">
            <h3 class="text-sm font-semibold leading-tight" x-text="s.t('card.title')"></h3>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                @if($batch)
                    <span class="font-medium text-fg" x-text="s.t('batch.counter', { i: {{ $c }}.index + 1, n: {{ $c }}.total })"></span>
                @else
                    <span x-text="s.t('card.revision', { n: {{ $c }}.draft.revision })"></span>
                @endif
                <span class="text-faint" aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1.5" x-bind:class="{{ $c }}.missing.length ? 'text-warn' : 'text-success'">
                    <span class="size-1.5 rounded-full bg-current"></span>
                    <span x-text="s.t('card.required_progress', { done: 5 - {{ $c }}.missing.length, total: 5 })"></span>
                </span>
                <span class="badge bg-success-soft text-success" x-show="{{ $c }}.draft.status === 'created'" x-text="{{ $c }}.draft.issue_key"></span>
                <span class="badge bg-danger-soft text-danger" x-show="{{ $c }}.draft.status === 'error'" x-text="s.t('card.status.error')"></span>
            </p>
        </div>

        <div class="ml-auto flex rounded-xl border border-line bg-surface-2/60 p-0.5 text-xs" role="tablist">
            <button type="button" role="tab" class="rounded-[10px] px-3 py-1.5 font-medium transition" x-bind:aria-selected="s.cardTab === 'edit'" x-bind:class="s.cardTab === 'edit' ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg'" @click="s.cardTab = 'edit'" x-text="s.t('card.tab_edit')"></button>
            <button type="button" role="tab" class="rounded-[10px] px-3 py-1.5 font-medium transition" x-bind:aria-selected="s.cardTab === 'preview'" x-bind:class="s.cardTab === 'preview' ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg'" @click="s.cardTab = 'preview'" x-text="s.t('card.tab_preview')"></button>
        </div>
    </div>

    {{-- notices --}}
    <div class="space-y-2 px-4 sm:px-5" x-show="s.demo || {{ $c }}.missing.length || {{ $c }}.draft.status === 'error'">
        <p class="flex items-start gap-2 rounded-xl bg-warn-soft px-3.5 py-2.5 text-xs text-warn" x-show="s.demo">
            <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('card.demo_notice')"></span>
        </p>

        <div class="rounded-xl border border-danger/30 bg-danger-soft px-3.5 py-2.5" x-show="{{ $c }}.missing.length" role="alert">
            <p class="flex items-center gap-2 text-xs font-medium text-danger"><x-ui.icon name="alert" class="size-3.5" /><span x-text="s.t('card.missing_title')"></span></p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="f in {{ $c }}.missing" x-bind:key="f">
                    <button type="button" class="chip border-danger/40 bg-surface text-danger transition hover:bg-danger/10" @click="s.cardTab = 'edit'; $nextTick(() => s.focusField(f))" x-text="s.t('fields.' + f)"></button>
                </template>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-3.5 py-3" x-show="{{ $c }}.draft.status === 'error'" role="alert">
            <x-ui.icon name="alert" class="size-4 shrink-0 text-danger" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium text-danger" x-text="s.t('card.error_title')"></p>
                <p class="text-xs text-muted" x-text="{{ $c }}.draft.error_message"></p>
                <p class="text-xs text-muted" x-text="s.t('card.error_kept')"></p>
            </div>
            <button type="button" class="btn" @click="{{ $c }}.openConfirm()" x-bind:disabled="{{ $c }}.creating || !{{ $c }}.valid"><x-ui.icon name="refresh" /><span x-text="s.t('card.retry')"></span></button>
        </div>
    </div>

    {{-- edit --}}
    <div x-show="s.cardTab === 'edit'" class="space-y-5 px-4 pb-5 pt-4 sm:px-5">

        {{-- title + description --}}
        <div class="space-y-4">
            <x-issue.field :c="$c" field="summary" required>
                <input id="field-summary" class="field py-2.5 text-[15px] font-semibold" type="text" maxlength="255" x-bind:class="{{ $c }}.missing.includes('summary') && 'field-invalid'"
                       x-model="{{ $c }}.draft.summary" @input="{{ $c }}.setField('summary', $event.target.value)">
            </x-issue.field>

            <x-issue.field :c="$c" field="description" required>
                <textarea id="field-description" rows="8" class="field resize-y font-mono text-[13px] leading-relaxed" x-bind:class="{{ $c }}.missing.includes('description') && 'field-invalid'"
                          x-model="{{ $c }}.draft.description" @input="{{ $c }}.setField('description', $event.target.value)"></textarea>
            </x-issue.field>
        </div>

        {{-- possible duplicates --}}
        <div class="rounded-xl border border-warn/30 bg-warn-soft px-3.5 py-3" x-show="{{ $c }}.similarIssues.length && {{ $c }}.draft.status !== 'created'" x-cloak role="status" data-similar>
            <p class="flex items-center gap-2 text-xs font-medium text-warn"><x-ui.icon name="alert" class="size-3.5" /><span x-text="s.t('similar.title')"></span></p>
            <p class="mt-0.5 text-xs text-muted" x-text="s.t('similar.hint')"></p>
            <ul class="mt-2 space-y-1">
                <template x-for="i in {{ $c }}.similarIssues" x-bind:key="i.id">
                    <li>
                        <a x-bind:href="i.url" target="_blank" rel="noopener" x-bind:title="s.t('similar.open')" x-bind:aria-label="s.t('similar.open') + ': ' + i.id + ' ' + i.summary"
                           class="group -mx-2 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition hover:bg-surface/70 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent">
                            <span class="shrink-0 font-medium text-accent" x-text="i.id"></span>
                            <span class="min-w-0 flex-1 truncate group-hover:underline" x-text="i.summary"></span>
                            <span class="badge shrink-0 bg-surface-3 text-muted" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                            <x-ui.icon name="external" class="size-3.5 shrink-0 text-muted transition group-hover:text-accent" />
                        </a>
                    </li>
                </template>
            </ul>
            @if($batch)
                <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm" x-show="{{ $c }}.duplicates.length">
                    <input type="checkbox" class="mt-0.5 size-4 accent-[var(--accent)]" x-model="{{ $c }}.draft.dupAck">
                    <span x-text="s.t('similar.ack')"></span>
                </label>
            @endif
        </div>

        {{-- properties --}}
        <section class="rounded-2xl border border-line bg-surface-2/40 p-4" aria-labelledby="card-props">
            <h4 id="card-props" class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="s.t('card.section_properties')"></h4>
            <div class="grid gap-4 sm:grid-flow-col sm:grid-cols-2 sm:grid-rows-2">
                <x-issue.field :c="$c" field="project" required>
                    <x-ui.select id="field-project" value="{{ $c }}.draft.project" select="{{ $c }}.setField('project', $value || null)"
                        options="(s.projects ?? []).map((p) => s.projectOption(p))" invalid="{{ $c }}.missing.includes('project')" on-open="s.loadProjects()" />
                </x-issue.field>

                <x-issue.field :c="$c" field="assignee">
                    <x-ui.select id="field-assignee" value="{{ $c }}.draft.assignee" select="{{ $c }}.setField('assignee', $value || null)"
                        options="{{ $c }}.meta.assignees.map((a) => s.personOption(a))" empty-label="s.t('card.unassigned')" />
                </x-issue.field>

                <x-issue.field :c="$c" field="priority" required>
                    <x-ui.select id="field-priority" value="{{ $c }}.draft.priority" select="{{ $c }}.setField('priority', $value || null)"
                        options="{{ $c }}.meta.priorities.map((p) => ({ value: p, label: s.lbl('priority', p) }))" invalid="{{ $c }}.missing.includes('priority')" />
                </x-issue.field>
            </div>

            <div class="mt-4">
                <x-issue.field :c="$c" field="type" required>
                    <div class="flex flex-wrap gap-1.5" role="radiogroup" x-bind:aria-label="s.t('fields.type')">
                        <template x-for="(t, i) in {{ $c }}.meta.types" x-bind:key="t">
                            <button type="button" role="radio" x-bind:id="i === 0 ? 'field-type' : null" x-bind:aria-checked="{{ $c }}.draft.type === t"
                                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    x-bind:class="{{ $c }}.draft.type === t ? 'border-transparent bg-accent text-accent-fg' : ({{ $c }}.missing.includes('type') ? 'border-danger/50 text-muted hover:text-fg' : 'border-line bg-surface text-muted hover:border-line-strong hover:text-fg')"
                                    @click="{{ $c }}.setField('type', t)">
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
                <p class="text-xs text-muted sm:col-span-2" x-show="!{{ $c }}.draft.project" x-text="s.t('card.need_project')"></p>

                <x-issue.field :c="$c" field="sprint" x-show="{{ $c }}.meta.sprints.length">
                    <x-ui.select id="field-sprint" value="{{ $c }}.draft.sprint" select="{{ $c }}.setField('sprint', $value || null)"
                        options="{{ $c }}.meta.sprints.map((o) => ({ value: o, label: s.lbl('sprint', o) }))" empty-label="s.t('card.none')" />
                </x-issue.field>
                <x-issue.field :c="$c" field="subsystem" x-show="{{ $c }}.meta.subsystems.length">
                    <x-ui.select id="field-subsystem" value="{{ $c }}.draft.subsystem" select="{{ $c }}.setField('subsystem', $value || null)"
                        options="{{ $c }}.meta.subsystems.map((o) => ({ value: o, label: s.lbl('subsystem', o) }))" empty-label="s.t('card.none')" />
                </x-issue.field>

                <div>
                    <label for="field-labels" class="label" x-text="s.t('fields.labels')"></label>
                    <div class="field flex flex-wrap items-center gap-1.5 py-1.5">
                        <template x-for="l in {{ $c }}.draft.labels" x-bind:key="l">
                            <span class="chip bg-surface-3 text-fg"><span x-text="l"></span>
                                <button type="button" class="text-muted hover:text-fg" @click="{{ $c }}.removeLabel(l)" x-bind:aria-label="s.t('common.remove') + ' ' + l"><x-ui.icon name="x" class="size-3" /></button>
                            </span>
                        </template>
                        <input id="field-labels" class="min-w-[6rem] flex-1 bg-transparent py-0.5 text-sm placeholder:text-faint focus:outline-none" x-model="labelInput" x-bind:placeholder="s.t('card.add_label')"
                               @keydown.enter.prevent="{{ $c }}.addLabel(labelInput); labelInput = ''" @keydown.comma.prevent="{{ $c }}.addLabel(labelInput); labelInput = ''" @blur="{{ $c }}.addLabel(labelInput); labelInput = ''">
                    </div>
                </div>

                <template x-for="cf in {{ $c }}.meta.custom" x-bind:key="cf.key">
                    <div>
                        <label class="label" x-text="s.lbl('custom', cf.key)"></label>
                        <x-ui.select value="{{ $c }}.draft.custom_fields[cf.key] ?? ''" select="{{ $c }}.setCustom(cf.key, $value || null)"
                            options="cf.values.map((v) => ({ value: v, label: s.lbl('customValue', v) }))" empty-label="s.t('card.none')" label="s.lbl('custom', cf.key)" />
                    </div>
                </template>
            </div>
        </section>

        {{-- attachments of a batch proposal: each issue picks its own files from the conversation --}}
        @if($batch)
        <section x-show="s.conversationAttachments.length" aria-labelledby="card-atts-batch">
            <div class="mb-2.5 flex items-center gap-2">
                <h4 id="card-atts-batch" class="text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="s.t('card.attachments_title')"></h4>
                <span class="badge bg-surface-3 text-muted" x-text="s.t({{ $c }}.attachmentCount === 1 ? 'batch.attachments_one' : 'batch.attachments_count', { n: {{ $c }}.attachmentCount })"></span>
                <button type="button" class="ml-auto text-xs font-medium text-accent hover:underline" @click="{{ $c }}.toggleAllAttachments()" x-text="{{ $c }}.allAttachments ? s.t('batch.select_none') : s.t('batch.select_all')"></button>
            </div>
            <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                <template x-for="a in s.conversationAttachments" x-bind:key="a.id">
                    <li>
                        <label class="group relative block cursor-pointer overflow-hidden rounded-xl border bg-surface transition"
                               x-bind:class="{{ $c }}.hasAttachment(a) ? 'border-line-strong' : 'border-line opacity-60 hover:opacity-100'">
                            <div class="flex h-20 items-center justify-center" x-bind:class="a.kind === 'image' ? 'bg-white' : 'bg-surface-2'">
                                <template x-if="a.kind === 'image'"><img x-bind:src="a.url" x-bind:alt="a.name" class="size-full object-contain p-1"></template>
                                <template x-if="a.kind !== 'image'"><span class="size-7 text-muted"><x-ui.icon name="file" class="size-full" /></span></template>
                            </div>
                            <input type="checkbox" class="absolute left-2 top-2 size-4 rounded shadow ring-2 ring-surface accent-[var(--accent)]" x-bind:checked="{{ $c }}.hasAttachment(a)" @change="{{ $c }}.toggleAttachment(a)" x-bind:aria-label="a.name">
                            <div class="px-2.5 py-2">
                                <p class="truncate text-xs font-medium" x-text="a.name"></p>
                                <p class="mt-0.5 text-[11px] text-faint" x-text="s.size(a.size ?? 0)"></p>
                            </div>
                        </label>
                    </li>
                </template>
            </ul>
            <p class="mt-2 text-xs text-muted" x-text="s.t('batch.attachments_hint')"></p>
        </section>
        @endif
        @unless($batch)
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
        @endunless
    </div>

    {{-- preview --}}
    <div x-show="s.cardTab === 'preview'" x-cloak class="space-y-4 px-4 pb-5 pt-4 sm:px-5">
        <h4 class="text-lg font-semibold leading-snug" x-text="{{ $c }}.draft.summary || s.t('card.no_summary')"></h4>
        <dl class="grid gap-x-6 gap-y-2.5 rounded-xl border border-line bg-surface-2/40 p-4 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
            <dt class="text-muted" x-text="s.t('fields.project')"></dt><dd class="font-medium" x-text="{{ $c }}.draft.project || '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.type')"></dt><dd class="font-medium" x-text="{{ $c }}.draft.type ? s.lbl('type', {{ $c }}.draft.type) : '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.priority')"></dt><dd class="font-medium" x-text="{{ $c }}.draft.priority ? s.lbl('priority', {{ $c }}.draft.priority) : '—'"></dd>
            <dt class="text-muted" x-text="s.t('fields.assignee')"></dt><dd class="font-medium" x-text="{{ $c }}.draft.assignee ? ({{ $c }}.meta.assignees.find((a) => a.value === {{ $c }}.draft.assignee)?.label ?? {{ $c }}.draft.assignee) : '—'"></dd>
        </dl>
        <div class="flex flex-wrap gap-1.5 text-xs" x-show="{{ $c }}.draft.labels.length || Object.keys({{ $c }}.draft.custom_fields ?? {}).length">
            <template x-for="(v, k) in ({{ $c }}.draft.custom_fields ?? {})" x-bind:key="k"><span class="chip"><span x-text="s.lbl('custom', k)"></span>: <span x-text="s.lbl('customValue', v)"></span></span></template>
            <template x-for="l in {{ $c }}.draft.labels" x-bind:key="l"><span class="chip"><x-ui.icon name="tag" class="size-3" /><span x-text="l"></span></span></template>
        </div>
        <div class="prose-msg rounded-xl border border-line p-4 text-sm" x-html="s.render({{ $c }}.draft.description)"></div>
    </div>

    {{-- create / save (not in a batch: there the approve/reject buttons below the card decide) --}}
    @unless($batch)
    {{-- create / save --}}
    <div class="border-t border-line bg-surface-2/40 px-4 py-4 sm:px-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="flex min-w-0 items-center gap-2 text-xs text-muted">
                <span class="size-3.5 shrink-0" x-bind:class="{{ $c }}.valid ? 'text-success' : 'text-warn'" x-html="s.icon({{ $c }}.valid ? 'check-circle' : 'alert')"></span>
                <span x-show="!{{ $c }}.valid" x-text="s.t('card.complete_to_create')"></span>
                <span x-show="{{ $c }}.valid" x-text="s.t('card.confirm_needed')"></span>
            </p>
            <button type="button" class="btn btn-primary ml-auto px-4" @click="{{ $c }}.openConfirm()" x-bind:disabled="!{{ $c }}.valid || {{ $c }}.creating || s.processing">
                <span class="size-4" x-bind:class="{{ $c }}.creating && 'anim-spin'" x-html="s.icon({{ $c }}.creating ? 'loader' : 'check')"></span>
                <span x-text="{{ $c }}.creating ? s.t('card.creating') : ({{ $c }}.draft.status === 'created' ? s.t('card.save_changes', { key: {{ $c }}.draft.issue_key }) : s.t('card.create'))"></span>
            </button>
        </div>
    </div>
    @endunless
</div>

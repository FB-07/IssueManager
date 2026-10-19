<div class="anim-rise mt-4 overflow-hidden rounded-2xl border border-line bg-surface" x-data="{ ask: '', labelInput: '' }" aria-label="Issue">
    {{-- header --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
        <span class="flex size-7 items-center justify-center rounded-lg bg-surface-3 text-muted"><x-ui.icon name="file" /></span>
        <h3 class="text-sm font-semibold" x-text="s.t('card.title')"></h3>
        <span class="badge bg-surface-3 text-muted" x-text="s.t('card.revision', { n: s.draft.revision })"></span>
        <span class="badge" x-show="s.draft.status === 'draft'" x-bind:class="'bg-accent-soft text-accent'" x-text="s.t('card.status.draft')"></span>
        <span class="badge bg-success-soft text-success" x-show="s.draft.status === 'created'" x-text="s.draft.issue_key"></span>
        <span class="badge bg-danger-soft text-danger" x-show="s.draft.status === 'error'" x-text="s.t('card.status.error')"></span>

        <div class="ml-auto flex rounded-lg border border-line p-0.5 text-xs" role="tablist">
            <button type="button" role="tab" class="rounded-md px-2.5 py-1 font-medium transition" x-bind:aria-selected="s.cardTab === 'edit'" x-bind:class="s.cardTab === 'edit' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.cardTab = 'edit'" x-text="s.t('card.tab_edit')"></button>
            <button type="button" role="tab" class="rounded-md px-2.5 py-1 font-medium transition" x-bind:aria-selected="s.cardTab === 'preview'" x-bind:class="s.cardTab === 'preview' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.cardTab = 'preview'" x-text="s.t('card.tab_preview')"></button>
        </div>
    </div>

    {{-- demo notice --}}
    <p class="flex items-start gap-2 border-b border-line bg-warn-soft px-4 py-2 text-xs text-warn" x-show="s.demo">
        <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('card.demo_notice')"></span>
    </p>

    {{-- missing fields --}}
    <div class="border-b border-line bg-danger-soft px-4 py-2.5" x-show="s.missing.length" role="alert">
        <p class="text-xs font-medium text-danger" x-text="s.t('card.missing_title')"></p>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
            <template x-for="f in s.missing" x-bind:key="f">
                <button type="button" class="chip border-danger/40 text-danger transition hover:bg-danger/10" @click="s.cardTab = 'edit'; $nextTick(() => s.focusField(f))" x-text="s.t('fields.' + f)"></button>
            </template>
        </div>
    </div>

    {{-- error from a failed creation --}}
    <div class="flex flex-wrap items-center gap-3 border-b border-line bg-danger-soft px-4 py-3" x-show="s.draft.status === 'error'" role="alert">
        <x-ui.icon name="alert" class="size-4 shrink-0 text-danger" />
        <div class="min-w-0 flex-1 text-sm">
            <p class="font-medium text-danger" x-text="s.t('card.error_title')"></p>
            <p class="text-xs text-muted" x-text="s.draft.error_message"></p>
            <p class="text-xs text-muted" x-text="s.t('card.error_kept')"></p>
        </div>
        <button type="button" class="btn" @click="s.openConfirm()" x-bind:disabled="s.creating || !s.valid"><x-ui.icon name="refresh" /><span x-text="s.t('card.retry')"></span></button>
    </div>

    {{-- edit --}}
    <div x-show="s.cardTab === 'edit'" class="space-y-4 p-4">
        <x-issue.field field="summary" required>
            <input id="field-summary" class="field" type="text" maxlength="255" x-bind:class="s.missing.includes('summary') && 'field-invalid'"
                   x-model="s.draft.summary" @input="s.setField('summary', $event.target.value)">
        </x-issue.field>

        <x-issue.field field="description" required>
            <textarea id="field-description" rows="9" class="field resize-y font-mono text-[13px] leading-relaxed" x-bind:class="s.missing.includes('description') && 'field-invalid'"
                      x-model="s.draft.description" @input="s.setField('description', $event.target.value)"></textarea>
        </x-issue.field>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-issue.field field="project" required>
                <select id="field-project" class="field" x-bind:class="s.missing.includes('project') && 'field-invalid'" x-model="s.draft.project" @change="s.setField('project', $event.target.value || null)" @focus="s.loadProjects()">
                    <option value="" x-text="s.t('card.choose')"></option>
                    <template x-for="p in (s.projects ?? [])" x-bind:key="p.key"><option x-bind:value="p.key" x-text="p.key + ' · ' + p.name" x-bind:selected="s.draft.project === p.key"></option></template>
                </select>
            </x-issue.field>

            <x-issue.field field="priority" required>
                <select id="field-priority" class="field" x-bind:class="s.missing.includes('priority') && 'field-invalid'" x-model="s.draft.priority" @change="s.setField('priority', $event.target.value || null)">
                    <option value="" x-text="s.t('card.choose')"></option>
                    <template x-for="p in s.meta.priorities" x-bind:key="p"><option x-bind:value="p" x-text="s.lbl('priority', p)" x-bind:selected="s.draft.priority === p"></option></template>
                </select>
            </x-issue.field>
        </div>

        <x-issue.field field="type" required>
            <div class="inline-flex flex-wrap rounded-lg border p-0.5" x-bind:class="s.missing.includes('type') ? 'border-danger/70' : 'border-line'" role="radiogroup" x-bind:aria-label="s.t('fields.type')">
                <template x-for="(t, i) in s.meta.types" x-bind:key="t">
                    <button type="button" role="radio" x-bind:id="i === 0 ? 'field-type' : null" x-bind:aria-checked="s.draft.type === t"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
                            x-bind:class="s.draft.type === t ? 'bg-accent-soft text-accent' : 'text-muted hover:text-fg'"
                            @click="s.setField('type', t)">
                        <span class="size-3.5" x-html="s.icon(['Bug', 'Anomaly'].includes(t) ? 'bug' : ['Feature', 'Improvement'].includes(t) ? 'sparkles' : 'check-circle')"></span>
                        <span x-text="s.lbl('type', t)"></span>
                    </button>
                </template>
            </div>
        </x-issue.field>

        {{-- advanced --}}
        <div class="rounded-xl border border-line">
            <button type="button" class="flex w-full items-center gap-2 px-3.5 py-2.5 text-left text-sm font-medium" @click="s.advancedOpen = !s.advancedOpen" x-bind:aria-expanded="s.advancedOpen">
                <span class="size-4 text-muted transition" x-bind:class="s.advancedOpen && 'rotate-90'" x-html="s.icon('chevron-right')"></span>
                <span x-text="s.t('card.advanced')"></span>
                <span class="ml-auto text-xs font-normal text-faint" x-text="s.t('card.optional')"></span>
            </button>
            <div x-show="s.advancedOpen" x-collapse.duration.200ms x-cloak class="grid gap-4 border-t border-line p-3.5 sm:grid-cols-2">
                <p class="text-xs text-muted sm:col-span-2" x-show="!s.draft.project" x-text="s.t('card.need_project')"></p>

                <x-issue.field field="assignee">
                    <select id="field-assignee" class="field" x-model="s.draft.assignee" @change="s.setField('assignee', $event.target.value || null)">
                        <option value="" x-text="s.t('card.unassigned')"></option>
                        <template x-for="a in s.meta.assignees" x-bind:key="a.value"><option x-bind:value="a.value" x-text="a.label" x-bind:selected="s.draft.assignee === a.value"></option></template>
                    </select>
                </x-issue.field>
                <x-issue.field field="sprint" x-show="s.meta.sprints.length">
                    <select id="field-sprint" class="field" x-model="s.draft.sprint" @change="s.setField('sprint', $event.target.value || null)">
                        <option value="" x-text="s.t('card.none')"></option>
                        <template x-for="o in s.meta.sprints" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('sprint', o)" x-bind:selected="s.draft.sprint === o"></option></template>
                    </select>
                </x-issue.field>
                <x-issue.field field="subsystem" x-show="s.meta.subsystems.length">
                    <select id="field-subsystem" class="field" x-model="s.draft.subsystem" @change="s.setField('subsystem', $event.target.value || null)">
                        <option value="" x-text="s.t('card.none')"></option>
                        <template x-for="o in s.meta.subsystems" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('subsystem', o)" x-bind:selected="s.draft.subsystem === o"></option></template>
                    </select>
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
                        <select class="field" x-bind:value="s.draft.custom_fields[cf.key] ?? ''" @change="s.setCustom(cf.key, $event.target.value || null)">
                            <option value="" x-text="s.t('card.none')"></option>
                            <template x-for="v in cf.values" x-bind:key="v"><option x-bind:value="v" x-text="s.lbl('customValue', v)" x-bind:selected="s.draft.custom_fields[cf.key] === v"></option></template>
                        </select>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- preview --}}
    <div x-show="s.cardTab === 'preview'" x-cloak class="space-y-3 p-4">
        <h4 class="text-lg font-semibold leading-snug" x-text="s.draft.summary || s.t('card.no_summary')"></h4>
        <div class="flex flex-wrap gap-1.5 text-xs">
            <span class="chip" x-show="s.draft.project" x-text="s.draft.project"></span>
            <span class="chip" x-show="s.draft.type" x-text="s.lbl('type', s.draft.type)"></span>
            <span class="chip" x-show="s.draft.priority" x-text="s.lbl('priority', s.draft.priority)"></span>
            <span class="chip" x-show="s.draft.assignee" x-text="s.meta.assignees.find((a) => a.value === s.draft.assignee)?.label ?? s.draft.assignee"></span>
            <template x-for="l in s.draft.labels" x-bind:key="l"><span class="chip"><x-ui.icon name="tag" class="size-3" /><span x-text="l"></span></span></template>
        </div>
        <div class="prose-msg text-sm" x-html="s.render(s.draft.description)"></div>
    </div>

    {{-- ask AI + create --}}
    <div class="space-y-3 border-t border-line bg-surface-2/50 p-4">
        <form class="flex gap-2" @submit.prevent="s.askChange(ask); ask = ''">
            <input class="field" x-model="ask" x-bind:placeholder="s.t('card.ask_placeholder')" x-bind:aria-label="s.t('card.ask_placeholder')" maxlength="500" x-bind:disabled="s.processing">
            <button type="submit" class="btn shrink-0" x-bind:disabled="!ask.trim() || s.processing"><x-ui.icon name="sparkles" /><span class="hidden sm:inline" x-text="s.t('card.ask')"></span></button>
        </form>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-muted" x-show="!s.valid" x-text="s.t('card.complete_to_create')"></p>
            <p class="text-xs text-muted" x-show="s.valid" x-text="s.t('card.confirm_needed')"></p>
            <button type="button" class="btn btn-primary ml-auto" @click="s.openConfirm()" x-bind:disabled="!s.valid || s.creating || s.processing">
                <span class="size-4" x-bind:class="s.creating && 'anim-spin'" x-html="s.icon(s.creating ? 'loader' : 'check')"></span>
                <span x-text="s.creating ? s.t('card.creating') : (s.draft.status === 'created' ? s.t('card.save_changes', { key: s.draft.issue_key }) : s.t('card.create'))"></span>
            </button>
        </div>
    </div>
</div>

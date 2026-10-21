{{-- keyboard shortcuts --}}
<x-ui.modal open="s.shortcutsOpen" title="shortcuts.title" width="max-w-md">
    <dl class="space-y-2.5 text-sm">
        @foreach ([['mod+K', 'search_chats'], ['alt+N', 'new_chat'], ['alt+I', 'issues'], ['alt+M', 'mine'], ['alt+A', 'analytics'], ['alt+D', 'draft'], ['alt+B', 'sidebar'], ['/', 'focus'], ['?', 'help'], ['esc', 'close']] as [$keys, $label])
            <div class="flex items-center justify-between gap-4">
                <dt class="text-muted" x-text="s.t('shortcuts.{{ $label }}')"></dt>
                <dd class="flex shrink-0 gap-1">
                    @foreach (explode('+', $keys) as $k)
                        <kbd class="min-w-6 rounded-md border border-line bg-surface-2 px-1.5 py-0.5 text-center font-mono text-xs"
                             x-text="{{ $k === 'mod' ? "(/Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl')" : ($k === 'alt' ? "(/Mac|iPhone|iPad/.test(navigator.platform) ? '⌥' : 'Alt')" : "'".$k."'") }}"></kbd>
                    @endforeach
                </dd>
            </div>
        @endforeach
    </dl>
</x-ui.modal>

{{-- settings --}}
<x-ui.modal open="s.settingsOpen" title="settings.title">
    <div class="space-y-5">
        <div>
            <p class="label" x-text="s.t('settings.theme')"></p>
            <div class="inline-flex rounded-lg border border-line p-0.5">
                @foreach (['dark' => 'moon', 'light' => 'sun'] as $theme => $icon)
                    <button type="button" class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition" x-bind:class="s.theme === '{{ $theme }}' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.setTheme('{{ $theme }}')">
                        <x-ui.icon :name="$icon" /><span x-text="s.t('settings.theme_{{ $theme }}')"></span>
                    </button>
                @endforeach
            </div>
        </div>
        <div>
            <p class="label" x-text="s.t('settings.language')"></p>
            <div class="inline-flex rounded-lg border border-line p-0.5">
                <template x-for="l in s.locales" x-bind:key="l">
                    <button type="button" class="rounded-md px-3 py-1.5 text-sm transition" x-bind:class="s.locale === l ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.setLocale(l)" x-text="s.t('settings.lang_' + l)"></button>
                </template>
            </div>
            <p class="mt-1.5 text-xs text-faint" x-text="s.t('settings.language_hint')"></p>
        </div>
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" class="mt-1 size-4 accent-[var(--accent)]" x-model="s.draftEnabled">
            <span><span class="block text-sm font-medium" x-text="s.t('settings.draft_title')"></span>
                <span class="block text-xs leading-relaxed text-muted" x-text="s.t('settings.draft_text')"></span></span>
        </label>
        <div class="rounded-xl border border-line bg-surface-2 p-3.5">
            <p class="flex items-center gap-2 text-sm font-medium"><span class="size-4" x-bind:class="s.demo ? 'text-warn' : 'text-success'" x-html="s.icon(s.demo ? 'alert' : 'check-circle')"></span><span x-text="s.demo ? s.t('settings.demo_title') : s.t('settings.live_title')"></span></p>
            <p data-no-brand class="mt-1 text-xs leading-relaxed text-muted" x-text="s.demo ? s.t('settings.demo_text') : s.t('settings.live_text')"></p>
        </div>
        <div class="flex items-center gap-3 border-t border-line pt-4">
            <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent" x-text="s.user.name.slice(0, 1)"></span>
            <div class="min-w-0"><p class="truncate text-sm font-medium" x-text="s.user.name"></p><p class="truncate text-xs text-faint" x-text="s.user.email"></p></div>
            <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                @csrf
                <button type="submit" class="btn"><x-ui.icon name="logout" /><span x-text="s.t('settings.logout')"></span></button>
            </form>
        </div>
    </div>
</x-ui.modal>

{{-- explicit confirmation of the (simulated) creation --}}
<x-ui.modal open="s.confirmOpen" title="confirm.title">
    <div class="space-y-4" x-show="s.draft">
        <p class="text-sm text-muted" x-text="s.draft?.issue_key ? s.t(s.demo ? 'confirm.text_update' : 'confirm.text_update_live', { key: s.draft.issue_key }) : s.t(s.demo ? 'confirm.text' : 'confirm.text_live')"></p>
        <dl class="grid gap-x-4 gap-y-2 rounded-xl border border-line bg-surface-2 p-3.5 text-sm sm:grid-cols-[auto_1fr]">
            <template x-for="f in ['summary', 'project', 'type', 'priority']" x-bind:key="f">
                <div class="contents"><dt class="text-muted" x-text="s.t('fields.' + f)"></dt><dd class="break-words font-medium" x-text="f === 'type' || f === 'priority' ? s.lbl(f, s.draft?.[f]) : s.draft?.[f]"></dd></div>
            </template>
        </dl>
        {{-- look-alikes: a warning, and for probable duplicates an explicit acknowledgement --}}
        <div class="rounded-xl border px-3.5 py-3" x-show="s.similarIssues.length && !s.draft?.issue_key" x-cloak role="alert"
             x-bind:class="s.duplicates.length ? 'border-danger/40 bg-danger-soft' : 'border-warn/30 bg-warn-soft'">
            <p class="flex items-center gap-2 text-sm font-medium" x-bind:class="s.duplicates.length ? 'text-danger' : 'text-warn'">
                <x-ui.icon name="alert" class="size-4 shrink-0" /><span x-text="s.duplicates.length ? s.t('similar.confirm_dup') : s.t('similar.confirm_title')"></span>
            </p>
            <p class="mt-0.5 text-xs text-muted" x-text="s.t('similar.hint')"></p>
            <ul class="mt-2 space-y-1.5">
                <template x-for="i in s.similarIssues" x-bind:key="i.id">
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
            <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm" x-show="s.duplicates.length">
                <input type="checkbox" class="mt-0.5 size-4 accent-[var(--accent)]" x-model="s.dupAck">
                <span x-text="s.t('similar.ack')"></span>
            </label>
        </div>
        <p class="flex items-start gap-2 rounded-lg bg-warn-soft px-3 py-2 text-xs text-warn" x-show="s.demo"><x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('confirm.demo_warning')"></span></p>
        <p class="flex items-start gap-2 rounded-lg bg-accent-soft px-3 py-2 text-xs text-accent" x-show="!s.demo"><x-ui.icon name="check-circle" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('confirm.live_warning')"></span></p>
        <label class="flex cursor-pointer items-center gap-2.5 text-sm" x-show="s.demo">
            <input type="checkbox" class="size-4 accent-[var(--accent)]" x-model="s.simulateError">
            <span x-text="s.t('confirm.simulate_error')"></span>
        </label>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn" @click="s.confirmOpen = false" x-text="s.t('common.cancel')"></button>
            <button type="button" class="btn btn-primary" @click="s.confirmCreate()" x-bind:disabled="s.duplicates.length > 0 && !s.dupAck"
                    x-text="s.similarIssues.length && !s.draft?.issue_key ? s.t('similar.create_anyway') : s.t('confirm.confirm')"></button>
        </div>
    </div>
</x-ui.modal>

{{-- existing issues: search + edit --}}
<x-ui.modal open="s.issuesOpen" title-expr="s.issueEdit ? s.t('issues.details') : s.t('issues.title')" width="max-w-3xl">
    <div x-show="!s.issueEdit" class="space-y-3">
        <form class="flex gap-2" @submit.prevent="s.applyIssueFilters()">
            <input class="field" x-model="s.issueFilters.q" @input="s.searchIssuesSoon()" x-bind:placeholder="s.t('issues.search')" x-bind:aria-label="s.t('issues.search')" maxlength="200">
            <button class="btn" type="submit"><x-ui.icon name="search" /><span class="hidden sm:inline" x-text="s.t('issues.search_btn')"></span></button>
        </form>

        {{-- filters --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <x-ui.select trigger="py-1.5" searchable="true" value="s.issueFilters.project" select="s.issueFilters.project = $value; s.onIssueProjectChange()"
                options="s.issueFilterOptions.projects.map((p) => s.projectOption(p))" empty-label="s.t('issues.all_projects')" label="s.t('fields.project')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.issueFilters.type" select="s.issueFilters.type = $value; s.applyIssueFilters()"
                options="s.issueFilterOptions.types.map((o) => ({ value: o, label: s.lbl('type', o) }))" empty-label="s.t('issues.all_types')" label="s.t('fields.type')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.issueFilters.priority" select="s.issueFilters.priority = $value; s.applyIssueFilters()"
                options="s.issueFilterOptions.priorities.map((o) => ({ value: o, label: s.lbl('priority', o) }))" empty-label="s.t('issues.all_priorities')" label="s.t('fields.priority')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.issueFilters.state" select="s.issueFilters.state = $value; s.applyIssueFilters()"
                options="s.issueFilterOptions.states.map((o) => ({ value: o, label: s.lbl('state', o) }))" empty-label="s.t('issues.all_states')" label="s.t('issues.state')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.issueFilters.assignee" select="s.issueFilters.assignee = $value; s.applyIssueFilters()"
                options="s.issueFilterOptions.assignees.map((a) => s.personOption(a))" empty-label="s.t('issues.all_assignees')" label="s.t('fields.assignee')" />
            <button type="button" class="btn-ghost btn py-1.5" x-show="s.issueFiltersActive" @click="s.resetIssueFilters()"><x-ui.icon name="x" /><span x-text="s.t('issues.clear')"></span></button>
        </div>

        <div x-show="s.issuesLoading && !s.issueResults.length" x-cloak><x-ui.skeleton-list :groups="1" :rows="5" /></div>
        <p class="py-6 text-center text-sm text-faint" x-show="!s.issuesLoading && s.issueResults.length === 0" x-text="s.t('issues.none')"></p>

        <div class="max-h-[42dvh] overflow-y-auto rounded-xl border border-line transition-opacity" x-show="s.issueResults.length" x-bind:class="s.issuesLoading && 'opacity-50'">
            <ul class="divide-y divide-line">
                <template x-for="i in s.issueResults" x-bind:key="i.id">
                    <li><button type="button" class="flex w-full items-center gap-3 px-3.5 py-3 text-left transition hover:bg-surface-2" @click="s.issuePickMode ? s.pickIssue(i) : s.editIssue(i)">
                        <span class="w-24 shrink-0 font-mono text-xs text-muted" x-text="i.id"></span>
                        <span class="min-w-0 flex-1 truncate text-sm" x-text="i.summary"></span>
                        <span class="chip hidden sm:inline-flex" x-show="i.type" x-text="s.lbl('type', i.type)"></span>
                        <span class="chip hidden sm:inline-flex" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                        <span class="shrink-0 text-xs font-medium text-accent" x-show="s.issuePickMode" x-text="s.t('context.use')"></span>
                    </button></li>
                </template>
            </ul>
        </div>

        {{-- pagination --}}
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted" x-show="s.issueResults.length">
            <p x-text="s.issueRange ? (s.issueTotal !== null ? s.t('issues.range', { from: s.issueRange.from, to: s.issueRange.to, total: s.issueTotal }) : s.t('issues.range_unknown', { from: s.issueRange.from, to: s.issueRange.to })) : ''"></p>
            <div class="flex items-center gap-1.5">
                <div class="mr-2 flex items-center gap-1.5"><span x-text="s.t('issues.per_page')"></span>
                    <x-ui.select width="w-auto" trigger="w-20 py-1 text-xs" value="s.issuePerPage" select="s.setIssuePerPage($value)"
                        options="[12, 24, 48].map((n) => ({ value: n, label: String(n) }))" searchable="false" label="s.t('issues.per_page')" /></div>
                <button type="button" class="btn-icon size-8 rotate-180" @click="s.goToIssuePage(s.issuePage - 1)" x-bind:disabled="s.issuePage <= 1 || s.issuesLoading" x-bind:aria-label="s.t('issues.prev')"><x-ui.icon name="chevron-right" /></button>
                <template x-for="(n, idx) in s.issuePageButtons" x-bind:key="idx">
                    <span>
                        <template x-if="n === '…'"><span class="px-1">…</span></template>
                        <template x-if="n !== '…'"><button type="button" class="size-8 rounded-lg text-xs font-medium transition" x-bind:class="n === s.issuePage ? 'bg-accent-soft text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg'" x-bind:aria-current="n === s.issuePage ? 'page' : null" @click="s.goToIssuePage(n)" x-text="n"></button></template>
                    </span>
                </template>
                <span class="px-1" x-show="s.issueTotalPages === null" x-text="s.t('issues.page', { n: s.issuePage })"></span>
                <button type="button" class="btn-icon size-8" @click="s.goToIssuePage(s.issuePage + 1)" x-bind:disabled="s.issuesLoading || (s.issueTotalPages ? s.issuePage >= s.issueTotalPages : !s.issueHasMore)" x-bind:aria-label="s.t('issues.next')"><x-ui.icon name="chevron-right" /></button>
            </div>
        </div>
    </div>
    <form x-show="s.issueEdit" x-cloak class="space-y-4" @submit.prevent="s.saveIssue()">
        <template x-if="s.issueEdit">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <a class="inline-flex items-center gap-1.5 font-mono text-sm font-semibold hover:underline" x-bind:href="s.issueEdit.url" target="_blank" rel="noopener" x-bind:title="s.t('similar.open')">
                        <span x-text="s.issueEdit.id"></span><x-ui.icon name="external" class="size-3.5" />
                    </a>
                    <span class="chip" x-show="s.issueEdit.state" x-text="s.lbl('state', s.issueEdit.state)"></span>
                </div>
                {{-- what YouTrack knows about the issue (read only) --}}
                <dl class="grid gap-x-6 gap-y-3 rounded-xl border border-line bg-surface-2/50 p-3.5 text-sm sm:grid-cols-2">
                    <div x-show="s.issueEdit.project"><dt class="text-xs text-muted" x-text="s.t('fields.project')"></dt><dd class="font-medium" x-text="s.issueEdit.project_name ? s.issueEdit.project_name + ' (' + s.issueEdit.project + ')' : s.issueEdit.project"></dd></div>
                    <div x-show="s.issueEdit.reporter"><dt class="text-xs text-muted" x-text="s.t('issues.l_created_by')"></dt><dd class="font-medium" x-text="s.issueEdit.reporter"></dd></div>
                    <div x-show="s.issueEdit.created"><dt class="text-xs text-muted" x-text="s.t('issues.l_created')"></dt><dd class="font-medium" x-text="s.shortDate(s.issueEdit.created)"></dd></div>
                    <div x-show="s.issueEdit.updated"><dt class="text-xs text-muted" x-text="s.t('issues.l_updated')"></dt><dd class="font-medium" x-text="s.shortDate(s.issueEdit.updated)"></dd></div>
                    <div x-show="s.issueEdit.resolved"><dt class="text-xs text-muted" x-text="s.t('issues.l_resolved')"></dt><dd class="font-medium" x-text="s.shortDate(s.issueEdit.resolved)"></dd></div>
                    <div x-show="s.issueEdit.comments !== null && s.issueEdit.comments !== undefined"><dt class="text-xs text-muted" x-text="s.t('issues.l_comments')"></dt><dd class="font-medium" x-text="s.issueEdit.comments"></dd></div>
                    <template x-for="[name, value] in s.issueEditExtraFields" x-bind:key="name"><div><dt class="text-xs text-muted" x-text="s.lbl('custom', name)"></dt><dd class="font-medium" x-text="s.lbl('customValue', value)"></dd></div></template>
                    <div class="sm:col-span-2" x-show="(s.issueEdit.labels ?? []).length"><dt class="mb-1 text-xs text-muted" x-text="s.t('fields.labels')"></dt>
                        <dd class="flex flex-wrap gap-1.5"><template x-for="l in s.issueEdit.labels" x-bind:key="l"><span class="chip"><x-ui.icon name="tag" class="size-3" /><span x-text="l"></span></span></template></dd></div>
                </dl>
                <div><label class="label" x-text="s.t('fields.summary')"></label><input class="field" x-model="s.issueEdit.summary" maxlength="255" required></div>
                <div><label class="label" x-text="s.t('fields.description')"></label><textarea rows="5" class="field" x-model="s.issueEdit.description"></textarea></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" x-text="s.t('fields.type')"></label>
                        <x-ui.select value="s.issueEdit.type" select="s.issueEdit.type = $value" options="(s.issueMeta?.types ?? []).map((o) => ({ value: o, label: s.lbl('type', o) }))" label="s.t('fields.type')" /></div>
                    <div><label class="label" x-text="s.t('fields.priority')"></label>
                        <x-ui.select value="s.issueEdit.priority" select="s.issueEdit.priority = $value" options="(s.issueMeta?.priorities ?? []).map((o) => ({ value: o, label: s.lbl('priority', o) }))" label="s.t('fields.priority')" /></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" x-text="s.t('issues.state')"></label>
                        <x-ui.select value="s.issueEdit.state" select="s.issueEdit.state = $value" options="(s.issueMeta?.states ?? []).map((o) => ({ value: o, label: s.lbl('state', o) }))" label="s.t('issues.state')" /></div>
                    <div><label class="label" x-text="s.t('fields.assignee')"></label>
                        <x-ui.select searchable="true" value="s.issueEdit.assigneeValue" select="s.issueEdit.assigneeValue = $value" options="(s.issueMeta?.assignees ?? []).map((a) => s.personOption(a))" label="s.t('fields.assignee')" /></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2" x-show="(s.issueMeta?.custom ?? []).length">
                    <template x-for="cf in (s.issueMeta?.custom ?? [])" x-bind:key="cf.key">
                        <div><label class="label" x-text="s.lbl('custom', cf.key)"></label>
                            <x-ui.select value="s.issueEdit.custom[cf.key] ?? ''" select="s.issueEdit.custom[cf.key] = $value" options="cf.values.map((o) => ({ value: o, label: s.lbl('customValue', o) }))" label="s.lbl('custom', cf.key)" /></div>
                    </template>
                </div>
                <div x-show="(s.issueMeta?.subsystems ?? []).length">
                    <label class="label" x-text="s.t('fields.subsystem')"></label>
                    <x-ui.select value="s.issueEdit.subsystem" select="s.issueEdit.subsystem = $value" options="(s.issueMeta?.subsystems ?? []).map((o) => ({ value: o, label: o }))" label="s.t('fields.subsystem')" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn" @click="s.closeIssueEdit()" x-text="s.t('common.back')"></button>
                    <button type="submit" class="btn btn-primary" x-bind:disabled="s.issueSaving" x-text="s.t('common.save')"></button>
                </div>
            </div>
        </template>
    </form>
</x-ui.modal>

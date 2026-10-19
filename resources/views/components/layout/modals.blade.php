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
            <p class="mt-1 text-xs leading-relaxed text-muted" x-text="s.demo ? s.t('settings.demo_text') : s.t('settings.live_text')"></p>
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
        <p class="flex items-start gap-2 rounded-lg bg-warn-soft px-3 py-2 text-xs text-warn" x-show="s.demo"><x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('confirm.demo_warning')"></span></p>
        <p class="flex items-start gap-2 rounded-lg bg-accent-soft px-3 py-2 text-xs text-accent" x-show="!s.demo"><x-ui.icon name="check-circle" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('confirm.live_warning')"></span></p>
        <label class="flex cursor-pointer items-center gap-2.5 text-sm" x-show="s.demo">
            <input type="checkbox" class="size-4 accent-[var(--accent)]" x-model="s.simulateError">
            <span x-text="s.t('confirm.simulate_error')"></span>
        </label>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn" @click="s.confirmOpen = false" x-text="s.t('common.cancel')"></button>
            <button type="button" class="btn btn-primary" @click="s.confirmCreate()" x-text="s.t('confirm.confirm')"></button>
        </div>
    </div>
</x-ui.modal>

{{-- existing issues: search + edit --}}
<x-ui.modal open="s.issuesOpen" title="issues.title" width="max-w-3xl">
    <div x-show="!s.issueEdit" class="space-y-3">
        <form class="flex gap-2" @submit.prevent="s.applyIssueFilters()">
            <input class="field" x-model="s.issueFilters.q" x-bind:placeholder="s.t('issues.search')" x-bind:aria-label="s.t('issues.search')" maxlength="200">
            <button class="btn" type="submit"><x-ui.icon name="search" /><span class="hidden sm:inline" x-text="s.t('issues.search_btn')"></span></button>
        </form>

        {{-- filters --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <select class="field py-1.5" x-model="s.issueFilters.project" @change="s.onIssueProjectChange()" x-bind:aria-label="s.t('fields.project')">
                <option value="" x-text="s.t('issues.all_projects')"></option>
                <template x-for="p in s.issueFilterOptions.projects" x-bind:key="p.key"><option x-bind:value="p.key" x-text="p.key + ' · ' + p.name" x-bind:selected="s.issueFilters.project === p.key"></option></template>
            </select>
            <select class="field py-1.5" x-model="s.issueFilters.type" @change="s.applyIssueFilters()" x-bind:aria-label="s.t('fields.type')">
                <option value="" x-text="s.t('issues.all_types')"></option>
                <template x-for="o in s.issueFilterOptions.types" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('type', o)" x-bind:selected="s.issueFilters.type === o"></option></template>
            </select>
            <select class="field py-1.5" x-model="s.issueFilters.priority" @change="s.applyIssueFilters()" x-bind:aria-label="s.t('fields.priority')">
                <option value="" x-text="s.t('issues.all_priorities')"></option>
                <template x-for="o in s.issueFilterOptions.priorities" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('priority', o)" x-bind:selected="s.issueFilters.priority === o"></option></template>
            </select>
            <select class="field py-1.5" x-model="s.issueFilters.state" @change="s.applyIssueFilters()" x-bind:aria-label="s.t('issues.state')">
                <option value="" x-text="s.t('issues.all_states')"></option>
                <template x-for="o in s.issueFilterOptions.states" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('state', o)" x-bind:selected="s.issueFilters.state === o"></option></template>
            </select>
            <select class="field py-1.5" x-model="s.issueFilters.assignee" @change="s.applyIssueFilters()" x-bind:aria-label="s.t('fields.assignee')">
                <option value="" x-text="s.t('issues.all_assignees')"></option>
                <template x-for="a in s.issueFilterOptions.assignees" x-bind:key="a.value"><option x-bind:value="a.value" x-text="a.label" x-bind:selected="s.issueFilters.assignee === a.value"></option></template>
            </select>
            <button type="button" class="btn-ghost btn py-1.5" x-show="s.issueFiltersActive" @click="s.resetIssueFilters()"><x-ui.icon name="x" /><span x-text="s.t('issues.clear')"></span></button>
        </div>

        <p class="py-6 text-center text-sm text-faint" x-show="s.issuesLoading" x-text="s.t('common.loading')"></p>
        <p class="py-6 text-center text-sm text-faint" x-show="!s.issuesLoading && s.issueResults.length === 0" x-text="s.t('issues.none')"></p>

        <div class="max-h-[42dvh] overflow-y-auto rounded-xl border border-line transition-opacity" x-show="s.issueResults.length" x-bind:class="s.issuesLoading && 'opacity-50'">
            <ul class="divide-y divide-line">
                <template x-for="i in s.issueResults" x-bind:key="i.id">
                    <li><button type="button" class="flex w-full items-center gap-3 px-3.5 py-3 text-left transition hover:bg-surface-2" @click="s.editIssue(i)">
                        <span class="w-24 shrink-0 font-mono text-xs text-muted" x-text="i.id"></span>
                        <span class="min-w-0 flex-1 truncate text-sm" x-text="i.summary"></span>
                        <span class="chip hidden sm:inline-flex" x-show="i.type" x-text="s.lbl('type', i.type)"></span>
                        <span class="chip hidden sm:inline-flex" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                    </button></li>
                </template>
            </ul>
        </div>

        {{-- pagination --}}
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted" x-show="s.issueResults.length">
            <p x-text="s.issueRange ? (s.issueTotal !== null ? s.t('issues.range', { from: s.issueRange.from, to: s.issueRange.to, total: s.issueTotal }) : s.t('issues.range_unknown', { from: s.issueRange.from, to: s.issueRange.to })) : ''"></p>
            <div class="flex items-center gap-1.5">
                <label class="mr-2 flex items-center gap-1.5"><span x-text="s.t('issues.per_page')"></span>
                    <select class="field w-auto py-1 pr-7 text-xs" x-bind:value="s.issuePerPage" @change="s.setIssuePerPage($event.target.value)">
                        <template x-for="n in [25, 50, 100]" x-bind:key="n"><option x-bind:value="n" x-text="n" x-bind:selected="s.issuePerPage === n"></option></template>
                    </select></label>
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
                <p class="font-mono text-xs text-muted" x-text="s.issueEdit.id"></p>
                <div><label class="label" x-text="s.t('fields.summary')"></label><input class="field" x-model="s.issueEdit.summary" maxlength="255" required></div>
                <div><label class="label" x-text="s.t('fields.description')"></label><textarea rows="5" class="field" x-model="s.issueEdit.description"></textarea></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" x-text="s.t('fields.type')"></label>
                        <select class="field" x-model="s.issueEdit.type"><template x-for="o in (s.issueMeta?.types ?? [])" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('type', o)" x-bind:selected="s.issueEdit.type === o"></option></template></select></div>
                    <div><label class="label" x-text="s.t('fields.priority')"></label>
                        <select class="field" x-model="s.issueEdit.priority"><template x-for="o in (s.issueMeta?.priorities ?? [])" x-bind:key="o"><option x-bind:value="o" x-text="s.lbl('priority', o)" x-bind:selected="s.issueEdit.priority === o"></option></template></select></div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn" @click="s.issueEdit = null" x-text="s.t('common.back')"></button>
                    <button type="submit" class="btn btn-primary" x-bind:disabled="s.issueSaving" x-text="s.t('common.save')"></button>
                </div>
            </div>
        </template>
    </form>
</x-ui.modal>

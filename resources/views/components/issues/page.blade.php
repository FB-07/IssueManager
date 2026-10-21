{{-- "Existing issues": a page of its own (/issues/all) with every issue the user can see, grouped by project --}}
<section x-show="s.page === 'all'" x-cloak class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
    <h2 class="mb-6 text-2xl font-semibold tracking-tight" x-text="s.t('issues.title')"></h2>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center gap-2">
            <input class="field min-w-40 flex-1 py-1.5" x-model="s.issueFilters.q" @input="s.searchIssuesSoon()" x-bind:placeholder="s.t('issues.search')" x-bind:aria-label="s.t('issues.search')" maxlength="200">
            <button type="button" class="btn-icon size-9 border border-line" x-bind:class="s.mineSelectMode && 'border-accent/50 bg-accent-soft text-accent'" x-bind:aria-pressed="s.mineSelectMode" @click="s.toggleMineSelectMode()"
                    x-bind:aria-label="s.mineSelectMode ? s.t('common.cancel') : s.t('nav.select')" x-bind:title="s.mineSelectMode ? s.t('common.cancel') : s.t('nav.select')">
                <x-ui.icon name="pointer" class="size-[18px]" />
            </button>
        </div>

        {{-- filters --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
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
        </div>
        <button type="button" class="btn-ghost btn py-1.5" x-show="s.issueFiltersActive" x-cloak @click="s.resetIssueFilters()"><x-ui.icon name="x" /><span x-text="s.t('issues.clear')"></span></button>

        <p class="py-6 text-center text-sm text-faint" x-show="s.issuesLoading && !s.issueResults.length" x-text="s.t('common.loading')"></p>
        <p class="py-6 text-center text-sm text-faint" x-show="!s.issuesLoading && s.issueResults.length === 0" x-text="s.t('issues.none')"></p>

        {{-- one block per project --}}
        <div class="space-y-5 transition-opacity" x-show="s.issueResults.length" x-bind:class="s.issuesLoading && 'opacity-50'">
            <template x-for="g in s.issueGroups" x-bind:key="g.key">
                <div>
                    <h3 class="mb-2 flex items-center gap-2 px-1 text-[13px] font-semibold uppercase tracking-wider text-muted">
                        <x-ui.avatar of="g.icon" size="size-6" />
                        <span x-text="g.name"></span><span class="font-mono text-[11px] font-normal normal-case text-faint" x-show="g.name !== g.key" x-text="g.key"></span>
                        <span class="ml-auto text-[11px] font-normal normal-case text-faint" x-text="g.count"></span>
                    </h3>
                    <ul class="divide-y divide-line rounded-xl border border-line">
                        <template x-for="i in g.items" x-bind:key="i.id">
                            <li class="flex items-center transition" x-bind:class="s.isMineSelected(i.id) && 'bg-accent-soft/50'">
                                <button type="button" x-show="s.mineSelectMode" class="flex size-11 shrink-0 items-center justify-center" role="checkbox" x-bind:aria-checked="s.isMineSelected(i.id)" x-bind:aria-label="s.t('mine.select_issue', { key: i.id })" @click="s.toggleMineSelect(i)">
                                    <span class="flex size-[18px] items-center justify-center rounded border transition" x-bind:class="s.isMineSelected(i.id) ? 'border-accent bg-accent text-accent-fg' : 'border-line-strong'">
                                        <span class="size-3" x-show="s.isMineSelected(i.id)" x-html="s.icon('check')"></span>
                                    </span>
                                </button>
                                <button type="button" class="flex min-w-0 flex-1 items-center gap-3 py-3 pr-3.5 text-left transition hover:bg-surface-2" x-bind:class="!s.mineSelectMode && 'pl-3.5'" @click="s.mineSelectMode ? s.toggleMineSelect(i) : s.editMine(i)">
                                    <span class="w-28 shrink-0 whitespace-nowrap font-mono text-xs text-muted" x-text="i.id"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm" x-text="i.summary"></span>
                                        <span class="block truncate text-xs text-faint" x-text="[i.assignee, s.shortDate(i.updated)].filter(Boolean).join(' · ')"></span>
                                    </span>
                                    <span class="chip hidden sm:inline-flex" x-show="i.priority" x-text="s.lbl('priority', i.priority)"></span>
                                    <span class="chip hidden sm:inline-flex" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted" x-show="s.issueResults.length">
            <p x-text="s.issuesRangeLabel"></p>
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
    {{-- the options for the ticked issues, including a message that opens a new chat about them --}}
    <div x-show="s.mineSelected.length" x-cloak x-transition.opacity class="pointer-events-none sticky bottom-4 z-20 mt-6">
        <div class="pointer-events-auto mx-auto flex max-w-3xl flex-wrap items-center gap-2 rounded-2xl border border-line-strong bg-surface p-2 shadow-[var(--shadow)]">
            <span class="flex items-center gap-1 pl-2 text-sm font-medium">
                <span x-text="s.t('mine.selected', { n: s.mineSelected.length })"></span>
                <button type="button" class="btn-icon size-7" @click="s.clearMineSelection()" x-bind:aria-label="s.t('mine.clear')" x-bind:title="s.t('mine.clear')"><x-ui.icon name="x" class="size-3.5" /></button>
            </span>
            <form class="flex min-w-56 flex-1 items-center gap-1.5 rounded-full border border-line bg-surface-2 py-1 pl-4 pr-1" @submit.prevent="s.startChatFromMine()">
                <input class="min-w-0 flex-1 bg-transparent text-sm placeholder:text-faint focus:outline-none" maxlength="2000" x-model="s.mineText" x-bind:placeholder="s.t('mine.write_placeholder')" x-bind:aria-label="s.t('mine.write_placeholder')">
                <button type="submit" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-accent-fg transition hover:bg-accent-hover disabled:opacity-40" x-bind:disabled="!s.mineText.trim()" x-bind:aria-label="s.t('mine.send')" x-bind:title="s.t('mine.send')"><span class="size-4" x-html="s.icon('send')"></span></button>
            </form>
            <button type="button" class="btn" @click="s.startChatFromMine()"><x-ui.icon name="message" /><span class="hidden sm:inline" x-text="s.t('mine.open_chat')"></span></button>
        </div>
    </div>
</section>

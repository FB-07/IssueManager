{{-- "Your issues": a page of its own (/issues) with the account, the numbers and the list of the issues assigned to or created by the user --}}
<section x-show="s.page === 'mine'" x-cloak class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
    <h2 class="mb-6 text-2xl font-semibold tracking-tight" x-text="s.t('mine.title')"></h2>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent-soft text-base font-semibold text-accent">
                <img x-show="s.user.avatar" x-bind:src="s.user.avatar" alt="" class="size-full object-cover" x-on:error="$el.remove()">
                <span x-show="!s.user.avatar" x-text="s.user.name.slice(0, 1)"></span>
            </span>
            <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold" x-text="s.user.name"></p><p class="truncate text-xs text-muted" x-text="s.user.email"></p></div>
            <a class="btn" x-bind:href="s.trackerUrl" target="_blank" rel="noopener"><x-ui.icon name="external" /><span x-text="s.t('similar.open')"></span></a>
        </div>

        {{-- the numbers: each one shows exactly those issues --}}
        <div class="grid grid-cols-3 gap-2">
            @foreach ([['assigned_open', 'assigned', 'open', 'assigned_open'], ['assigned_resolved', 'assigned', 'resolved', 'assigned_resolved'], ['created', 'created', 'all', 'created']] as [$k, $scope, $status, $label])
                <button type="button" class="rounded-xl border px-3 py-2.5 text-left transition hover:bg-surface-2"
                        x-bind:class="s.mineScope === '{{ $scope }}' && s.mineStatus === '{{ $status }}' ? 'border-accent/50 bg-accent-soft' : 'border-line'" @click="s.showMine('{{ $scope }}', '{{ $status }}')">
                    <span class="block text-xl font-semibold tabular-nums" x-text="s.mineSummary?.{{ $k }} ?? '–'"></span>
                    <span class="block text-xs text-muted" x-text="s.t('mine.{{ $label }}')"></span>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-line p-0.5 text-xs font-medium">
                @foreach (['assigned', 'created'] as $scope)
                    <button type="button" class="rounded-md px-3 py-1.5 transition" x-bind:class="s.mineScope === '{{ $scope }}' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.showMine('{{ $scope }}', s.mineStatus)" x-text="s.t('mine.scope_{{ $scope }}')"></button>
                @endforeach
            </div>
            <div class="inline-flex rounded-lg border border-line p-0.5 text-xs font-medium">
                @foreach (['open', 'resolved', 'all'] as $status)
                    <button type="button" class="rounded-md px-3 py-1.5 transition" x-bind:class="s.mineStatus === '{{ $status }}' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" @click="s.showMine(s.mineScope, '{{ $status }}')" x-text="s.t('mine.status_{{ $status }}')"></button>
                @endforeach
            </div>
            <input class="field min-w-40 flex-1 py-1.5" x-model="s.mineQ" @input="s.searchMineSoon()" x-bind:placeholder="s.t('issues.search')" x-bind:aria-label="s.t('issues.search')" maxlength="200">
            <button type="button" class="btn-icon size-9 border border-line" x-bind:class="s.mineSelectMode && 'border-accent/50 bg-accent-soft text-accent'" x-bind:aria-pressed="s.mineSelectMode" @click="s.toggleMineSelectMode()"
                    x-bind:aria-label="s.mineSelectMode ? s.t('common.cancel') : s.t('nav.select')" x-bind:title="s.mineSelectMode ? s.t('common.cancel') : s.t('nav.select')">
                <x-ui.icon name="pointer" class="size-[18px]" />
            </button>
        </div>

        {{-- filters (the same ones as the list of existing issues) --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-ui.select trigger="py-1.5" searchable="true" value="s.mineFilters.project" select="s.setMineFilter('project', $value)"
                options="s.mineFilterOptions.projects.map((p) => s.projectOption(p))" empty-label="s.t('issues.all_projects')" label="s.t('fields.project')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.mineFilters.type" select="s.setMineFilter('type', $value)"
                options="s.mineFilterOptions.types.map((o) => ({ value: o, label: s.lbl('type', o) }))" empty-label="s.t('issues.all_types')" label="s.t('fields.type')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.mineFilters.priority" select="s.setMineFilter('priority', $value)"
                options="s.mineFilterOptions.priorities.map((o) => ({ value: o, label: s.lbl('priority', o) }))" empty-label="s.t('issues.all_priorities')" label="s.t('fields.priority')" />
            <x-ui.select trigger="py-1.5" searchable="true" value="s.mineFilters.state" select="s.setMineFilter('state', $value)"
                options="s.mineFilterOptions.states.map((o) => ({ value: o, label: s.lbl('state', o) }))" empty-label="s.t('issues.all_states')" label="s.t('issues.state')" />
        </div>
        <button type="button" class="btn-ghost btn py-1.5" x-show="s.mineFiltersActive" x-cloak @click="s.resetMineFilters()"><x-ui.icon name="x" /><span x-text="s.t('issues.clear')"></span></button>

        <p class="py-6 text-center text-sm text-faint" x-show="s.mineLoading && !s.mineResults.length" x-text="s.t('common.loading')"></p>
        <p class="py-6 text-center text-sm text-faint" x-show="!s.mineLoading && s.mineResults.length === 0" x-text="s.t('mine.none')"></p>

        {{-- one block per project --}}
        <div class="space-y-5 transition-opacity" x-show="s.mineResults.length" x-bind:class="s.mineLoading && 'opacity-50'">
            <template x-for="g in s.mineGroups" x-bind:key="g.key">
                <div>
                    <h3 class="mb-2 flex items-center gap-2 px-1 text-[13px] font-semibold uppercase tracking-wider text-muted">
                        <x-ui.avatar of="g.icon" size="size-6" />
                        <span x-text="g.name"></span><span class="font-mono text-[11px] font-normal normal-case text-faint" x-show="g.name !== g.key" x-text="g.key"></span>
                        <span class="ml-auto text-[11px] font-normal normal-case text-faint" x-text="g.items.length"></span>
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

        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted" x-show="s.mineResults.length">
            <p x-text="s.mineTotal !== null ? s.t('mine.total', { n: s.mineTotal }) : ''"></p>
            <div class="flex items-center gap-1.5">
                <div class="mr-2 flex items-center gap-1.5"><span x-text="s.t('issues.per_page')"></span>
                    <x-ui.select width="w-auto" trigger="w-20 py-1 text-xs" value="s.minePerPage" select="s.setMinePerPage($value)"
                        options="[12, 24, 48].map((n) => ({ value: n, label: String(n) }))" searchable="false" label="s.t('issues.per_page')" /></div>
                <button type="button" class="btn-icon size-8 rotate-180" @click="s.goToMinePage(s.minePage - 1)" x-bind:disabled="s.minePage <= 1 || s.mineLoading" x-bind:aria-label="s.t('issues.prev')"><x-ui.icon name="chevron-right" /></button>
                <template x-for="(n, idx) in s.minePageButtons" x-bind:key="idx">
                    <span>
                        <template x-if="n === '…'"><span class="px-1">…</span></template>
                        <template x-if="n !== '…'"><button type="button" class="size-8 rounded-lg text-xs font-medium transition" x-bind:class="n === s.minePage ? 'bg-accent-soft text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg'" x-bind:aria-current="n === s.minePage ? 'page' : null" @click="s.goToMinePage(n)" x-text="n"></button></template>
                    </span>
                </template>
                <span class="px-1" x-show="s.mineTotalPages === null" x-text="s.t('issues.page', { n: s.minePage })"></span>
                <button type="button" class="btn-icon size-8" @click="s.goToMinePage(s.minePage + 1)" x-bind:disabled="s.mineLoading || (s.mineTotalPages ? s.minePage >= s.mineTotalPages : !s.mineHasMore)" x-bind:aria-label="s.t('issues.next')"><x-ui.icon name="chevron-right" /></button>
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

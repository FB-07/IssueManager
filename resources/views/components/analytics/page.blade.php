{{-- "Analysis": a page of its own (/analytics) with what was created and resolved per week / month / year, the open backlog, who has what open and how the open issues are spread --}}
<section x-show="s.page === 'analytics'" x-cloak class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h2 class="mr-auto text-2xl font-semibold tracking-tight" x-text="s.t('analytics.title')"></h2>
        <div class="inline-flex rounded-lg border border-line p-0.5 text-xs font-medium" role="group" x-bind:aria-label="s.t('analytics.period')">
            @foreach (['week', 'month', 'year'] as $g)
                <button type="button" class="rounded-md px-3 py-1.5 transition" x-bind:class="s.analyticsGranularity === '{{ $g }}' ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'" x-bind:aria-pressed="s.analyticsGranularity === '{{ $g }}'" @click="s.setAnalyticsGranularity('{{ $g }}')" x-text="s.t('analytics.by_{{ $g }}')"></button>
            @endforeach
        </div>
        <div class="w-56">
            <x-ui.select trigger="py-1.5" searchable="true" value="s.analyticsProject" select="s.setAnalyticsProject($value)"
                options="(s.catalog?.projects ?? []).map((p) => s.projectOption(p))" empty-label="s.t('issues.all_projects')" label="s.t('fields.project')" />
        </div>
        <button type="button" class="btn-icon size-9 border border-line" @click="s.loadAnalytics()" x-bind:disabled="s.analyticsLoading" x-bind:aria-label="s.t('analytics.refresh')" x-bind:title="s.t('analytics.refresh')">
            <span class="size-[18px]" x-bind:class="s.analyticsLoading && 'anim-spin'" x-html="s.icon('refresh')"></span>
        </button>
    </div>

    {{-- loading: the shape of the page --}}
    <div x-show="s.analyticsLoading && !s.analytics" x-cloak class="space-y-5" role="status" aria-busy="true">
        <span class="sr-only" x-text="s.t('common.loading')"></span>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" aria-hidden="true">@foreach (range(1, 6) as $n)<span class="skeleton h-20 rounded-xl"></span>@endforeach</div>
        <span class="skeleton block h-72 rounded-2xl" aria-hidden="true"></span>
        <span class="skeleton block h-56 rounded-2xl" aria-hidden="true"></span>
    </div>

    <div x-show="s.analytics" x-cloak class="space-y-6 transition-opacity" x-bind:class="s.analyticsLoading && 'opacity-50'">
        <p class="rounded-lg border border-warn/30 bg-warn-soft px-3 py-2 text-xs text-warn" x-show="s.analytics?.capped" x-text="s.t('analytics.capped')"></p>

        {{-- the numbers --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                ['open', "s.analytics?.kpis.open", 'kpi_open', null],
                ['created', "s.analytics?.kpis.created_in_range", 'kpi_created', 'range'],
                ['resolved', "s.analytics?.kpis.resolved_in_range", 'kpi_resolved', 'range'],
                ['rate', "s.analytics?.kpis.resolution_rate != null ? s.analytics.kpis.resolution_rate + '%' : '–'", 'kpi_rate', 'range'],
                ['unassigned', "s.analytics?.kpis.unassigned_open", 'kpi_unassigned', null],
            ] as [$id, $value, $label, $sub])
                <div class="rounded-xl border border-line px-3.5 py-3">
                    <p class="text-xs text-muted" x-text="s.t('analytics.{{ $label }}')"></p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums" x-text="{!! $value !!}"></p>
                    @if ($sub === 'range')
                        <p class="mt-0.5 text-[11px] leading-snug text-faint" x-text="s.t('analytics.range_' + s.analyticsGranularity)"></p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- created vs resolved --}}
        <div class="rounded-2xl border border-line p-4 sm:p-5">
            <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1">
                <h3 class="mr-auto text-sm font-semibold" x-text="s.t('analytics.chart_flow')"></h3>
                <span class="inline-flex items-center gap-1.5 text-xs text-muted"><i class="size-2.5 rounded-sm" style="background: var(--viz-1)"></i><span x-text="s.t('analytics.created')"></span></span>
                <span class="inline-flex items-center gap-1.5 text-xs text-muted"><i class="size-2.5 rounded-sm" style="background: var(--viz-3)"></i><span x-text="s.t('analytics.resolved')"></span></span>
            </div>
            <svg viewBox="0 0 720 260" class="block h-auto w-full" role="img" x-bind:aria-label="s.t('analytics.chart_flow')" x-html="s.analyticsBars.svg"></svg>
        </div>

        {{-- the backlog --}}
        <div class="rounded-2xl border border-line p-4 sm:p-5">
            <div class="mb-3 flex items-baseline gap-3">
                <h3 class="mr-auto text-sm font-semibold" x-text="s.t('analytics.chart_backlog')"></h3>
                <span class="text-xs text-muted" x-text="s.t('analytics.chart_backlog_hint')"></span>
            </div>
            <svg viewBox="0 0 720 260" class="block h-auto w-full" role="img" x-bind:aria-label="s.t('analytics.chart_backlog')" x-html="s.analyticsBacklog.svg"></svg>
        </div>

        {{-- who has what open --}}
        <div class="rounded-2xl border border-line">
            <h3 class="border-b border-line px-4 py-3 text-sm font-semibold sm:px-5" x-text="s.t('analytics.people')"></h3>
            <div class="max-h-[28rem] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-surface text-left text-xs text-muted">
                        <tr><th class="px-4 py-2 font-medium sm:px-5" x-text="s.t('fields.assignee')"></th><th class="px-2 py-2 font-medium" x-text="s.t('analytics.col_open')"></th><th class="px-4 py-2 text-right font-medium sm:px-5" x-text="s.t('analytics.col_resolved')"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="(p, n) in (s.analytics?.assignees ?? [])" x-bind:key="n">
                            <tr>
                                <td class="px-4 py-2.5 sm:px-5"><span class="flex items-center gap-2.5"><span class="relative flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-3 text-[10px] font-semibold text-muted"><span x-text="(p.name || '?').slice(0, 1).toUpperCase()"></span><template x-if="p.avatar"><img x-bind:src="p.avatar" alt="" loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover" x-on:error="$el.remove()"></template></span><span class="min-w-0 truncate" x-bind:class="!p.name && 'text-muted italic'" x-text="p.name || s.t('analytics.unassigned')"></span></span></td>
                                <td class="px-2 py-2.5"><span class="flex items-center gap-2"><span class="w-7 text-right font-semibold tabular-nums" x-text="p.open"></span><span class="hidden h-2 w-24 rounded-r-[4px] bg-surface-3/40 sm:block"><span class="block h-2 rounded-r-[4px]" x-bind:style="`width:${(p.open / s.barMax(s.analytics.assignees.map((x) => ({ count: x.open })))) * 100}%; background: var(--viz-1)`"></span></span></span></td>
                                <td class="px-4 py-2.5 text-right tabular-nums sm:px-5" x-text="p.resolved"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- how the open issues are spread --}}
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_state')"></h3>
                <x-analytics.bars of="(s.analytics?.states ?? []).map((i) => ({ ...i, name: i.name ? s.lbl('state', i.name) : s.t('analytics.none') }))" /></div>
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_age')"></h3>
                <x-analytics.bars of="(s.analytics?.aging ?? []).map((a, n) => ({ ...a, name: s.agingLabel(a, n) }))" /></div>
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_priority')"></h3>
                <x-analytics.bars of="(s.analytics?.priorities ?? []).map((i) => ({ ...i, name: i.name ? s.lbl('priority', i.name) : s.t('analytics.none') }))" /></div>
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_type')"></h3>
                <x-analytics.bars of="(s.analytics?.types ?? []).map((i) => ({ ...i, name: i.name ? s.lbl('type', i.name) : s.t('analytics.none') }))" /></div>
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_project')"></h3>
                <x-analytics.bars of="(s.analytics?.projects ?? []).map((i) => ({ ...i, name: i.name || i.key || s.t('analytics.none') }))" /></div>
            <div class="rounded-2xl border border-line p-4 sm:p-5"><h3 class="mb-3 text-sm font-semibold" x-text="s.t('analytics.by_reporter')"></h3>
                <x-analytics.bars of="(s.analytics?.reporters ?? []).map((i) => ({ ...i, name: i.name || s.t('analytics.none') }))" /></div>
        </div>

        {{-- the same numbers as a table --}}
        <details class="rounded-2xl border border-line">
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium sm:px-5" x-text="s.t('analytics.table')"></summary>
            <div class="overflow-x-auto border-t border-line">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted"><tr><th class="px-4 py-2 font-medium sm:px-5" x-text="s.t('analytics.period')"></th><th class="px-2 py-2 font-medium" x-text="s.t('analytics.created')"></th><th class="px-2 py-2 font-medium" x-text="s.t('analytics.resolved')"></th><th class="px-4 py-2 text-right font-medium sm:px-5" x-text="s.t('analytics.col_open_end')"></th></tr></thead>
                    <tbody class="divide-y divide-line tabular-nums">
                        <template x-for="(p, n) in (s.analytics?.series ?? [])" x-bind:key="n">
                            <tr><td class="px-4 py-2 sm:px-5" x-text="s.periodTitle(p)"></td><td class="px-2 py-2" x-text="p.created"></td><td class="px-2 py-2" x-text="p.resolved"></td><td class="px-4 py-2 text-right sm:px-5" x-text="p.open"></td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</section>

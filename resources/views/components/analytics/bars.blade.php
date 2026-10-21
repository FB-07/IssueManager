@props(['of', 'name' => 'i.name', 'color' => 'var(--viz-1)', 'empty' => 'analytics.no_data'])
{{-- A list of horizontal bars: label, bar (8px, rounded at its end, from one baseline), value. `of` is a JS expression returning [{count, ...}] --}}
<ul class="space-y-2.5" x-data="{ list: null }" x-effect="list = {!! $of !!}">
    <template x-for="(i, n) in list" x-bind:key="n">
        <li class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-sm">
            <span class="truncate text-muted" x-text="{!! $name !!}" x-bind:title="{!! $name !!}"></span>
            <span class="h-2 rounded-r-[4px] bg-surface-3/40"><span class="block h-2 rounded-r-[4px]" x-bind:style="`width:${Math.max(i.count ? 2 : 0, (i.count / s.barMax(list)) * 100)}%; background: {{ $color }}`"></span></span>
            <span class="tabular-nums font-medium" x-text="i.count"></span>
        </li>
    </template>
    <li class="text-sm text-faint" x-show="!list || !list.length" x-text="s.t('{{ $empty }}')"></li>
</ul>

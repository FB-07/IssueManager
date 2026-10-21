@props(['groups' => 2, 'rows' => 3])
{{-- Placeholder of a list of issues grouped by project (a header and a few rows per group) while it loads --}}
<div class="space-y-5" role="status" aria-busy="true" aria-live="polite">
    <span class="sr-only" x-text="s.t('common.loading')"></span>
    @foreach (range(1, $groups) as $g)
        <div aria-hidden="true">
            <div class="mb-2 flex items-center gap-2 px-1">
                <span class="skeleton size-6 rounded-md"></span>
                <span class="skeleton h-3.5 w-40"></span>
                <span class="skeleton ml-auto h-3 w-24"></span>
            </div>
            <ul class="divide-y divide-line rounded-xl border border-line">
                @foreach (range(1, $rows) as $r)
                    <li class="flex items-center gap-3 px-3.5 py-3.5">
                        <span class="skeleton h-3.5 w-24 shrink-0"></span>
                        <span class="min-w-0 flex-1 space-y-2">
                            <span class="skeleton block h-3.5 {{ ['w-3/4', 'w-2/3', 'w-5/6'][($g + $r) % 3] }}"></span>
                            <span class="skeleton block h-3 w-1/3"></span>
                        </span>
                        <span class="skeleton hidden h-6 w-16 rounded-full sm:block"></span>
                        <span class="skeleton hidden h-6 w-20 rounded-full sm:block"></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>

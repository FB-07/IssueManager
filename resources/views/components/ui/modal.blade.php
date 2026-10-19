@props(['open', 'title', 'width' => 'max-w-lg'])
<div x-show="{{ $open }}" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
     role="dialog" aria-modal="true" @keydown.escape.window="{{ $open }} = false">
    <div class="absolute inset-0 bg-black/55" x-show="{{ $open }}" x-transition.opacity @click="{{ $open }} = false"></div>
    <div x-show="{{ $open }}" x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-3 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         class="relative flex max-h-[92dvh] w-full {{ $width }} flex-col rounded-t-2xl border border-line bg-surface shadow-[var(--shadow)] sm:rounded-2xl">
        <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
            <h2 class="text-sm font-semibold" x-text="s.t('{{ $title }}')"></h2>
            <button type="button" class="btn-icon -mr-2 size-8" @click="{{ $open }} = false" x-bind:aria-label="s.t('common.close')">
                <x-ui.icon name="x" />
            </button>
        </div>
        <div class="overflow-y-auto p-5">{{ $slot }}</div>
    </div>
</div>

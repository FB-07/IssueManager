{{-- Renders attachment `a` (x-for scope) inside a sent message --}}
<div>
    <template x-if="a.kind === 'image' && a.url">
        <a x-bind:href="a.url" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-line">
            <img x-bind:src="a.url" x-bind:alt="a.name" class="size-24 object-cover sm:size-28">
        </a>
    </template>
    <template x-if="a.kind === 'file' || (a.kind === 'image' && !a.url)">
        <span class="flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-xs">
            <span class="size-4 text-muted" x-html="s.icon(a.kind === 'image' ? 'image' : 'file')"></span>
            <span class="max-w-[10rem] truncate" x-text="a.name"></span>
            <span class="text-faint" x-text="s.size(a.size ?? 0)"></span>
        </span>
    </template>
</div>

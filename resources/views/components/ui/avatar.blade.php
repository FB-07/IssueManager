@props(['of', 'size' => 'size-5'])
{{-- Image (or initials underneath it) of a dropdown option; `of` is a JS expression returning {image?, fallback?, shape?} --}}
<span class="relative inline-flex {{ $size }} shrink-0 items-center justify-center overflow-hidden bg-surface-3 text-[10px] font-semibold leading-none text-muted"
      x-show="({!! $of !!})?.image || ({!! $of !!})?.fallback"
      x-bind:class="({!! $of !!})?.shape === 'round' ? 'rounded-full' : 'rounded-md'" aria-hidden="true">
    <span x-text="({!! $of !!})?.fallback"></span>
    <template x-if="({!! $of !!})?.image">
        <img x-bind:src="({!! $of !!}).image" alt="" loading="lazy" decoding="async" class="absolute inset-0 size-full object-cover" x-on:error="$el.remove()">
    </template>
</span>

@props([
    'value',               {{-- JS expression: current value --}}
    'options',             {{-- JS expression: array of strings or {value, label, hint?} --}}
    'select',              {{-- JS statement(s); the chosen value is available as $value --}}
    'id' => null,
    'placeholder' => null, {{-- JS expression (string) --}}
    'emptyLabel' => null,  {{-- JS expression: adds a first "none/all" option that selects '' --}}
    'invalid' => 'false',  {{-- JS expression --}}
    'label' => null,       {{-- JS expression for aria-label when there is no <label for> --}}
    'searchable' => 'null',
    'disabled' => 'false',
    'onOpen' => null,
    'width' => 'w-full',
    'trigger' => '',       {{-- extra classes for the button (size) --}}
])
<div x-data="combo({
        options: () => ({!! $options !!}),
        value: () => ({!! $value !!}),
        pick: ($value) => { {!! $select !!} },
        placeholder: () => ({!! $placeholder ?? "s.t('card.choose')" !!}),
        emptyLabel: {!! $emptyLabel ? "() => ({$emptyLabel})" : 'null' !!},
        searchable: {!! $searchable !!},
        disabled: () => ({!! $disabled !!}),
        onOpen: {!! $onOpen ? "() => { {$onOpen} }" : 'null' !!},
    })"
    class="relative {{ $width }}"
    @mousedown.window="onWindowDown($event)"
    @resize.window="onViewportChange($event)"
    @scroll.window.capture.passive="onViewportChange($event)">

    <button type="button" x-ref="trigger" @if ($id) id="{{ $id }}" @endif
            class="field flex items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-50 {{ $trigger }}"
            x-bind:class="{ 'field-invalid': ({!! $invalid !!}), 'border-accent ring-2 ring-accent/25': open }"
            role="combobox" aria-haspopup="listbox" x-bind:aria-expanded="open" x-bind:aria-controls="uid + '-list'"
            @if ($label) x-bind:aria-label="{!! $label !!}" @endif
            x-bind:disabled="disabled" @click="toggle()" @keydown="onKey($event)">
        <span class="truncate" x-bind:class="isEmpty && 'text-faint'" x-text="label"></span>
        <span class="size-4 shrink-0 text-muted transition-transform duration-150" x-bind:class="open && 'rotate-180'"><x-ui.icon name="chevron-down" class="size-full" /></span>
    </button>

    <div x-ref="panel" x-show="open" x-cloak x-transition.opacity.duration.100ms x-bind:style="panelStyle"
         class="fixed z-[70] flex flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-[var(--shadow)]">
        <div class="border-b border-line p-1.5" x-show="searchable">
            <label class="relative block">
                <span class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-faint"><x-ui.icon name="search" class="size-full" /></span>
                <input x-ref="search" type="text" autocomplete="off" spellcheck="false" x-model="query" @input="active = 0; reveal()" @keydown="onKey($event)"
                       class="w-full rounded-lg bg-surface-2 py-1.5 pl-8 pr-2 text-sm placeholder:text-faint focus:outline-none focus:ring-2 focus:ring-accent/25"
                       x-bind:placeholder="s.t('dropdown.search')" x-bind:aria-label="s.t('dropdown.search')" x-bind:aria-controls="uid + '-list'" x-bind:aria-activedescendant="uid + '-' + active">
            </label>
        </div>
        <ul x-ref="list" x-bind:id="uid + '-list'" role="listbox" tabindex="-1" class="min-h-0 flex-1 overflow-y-auto p-1 focus:outline-none" @keydown="onKey($event)">
            <template x-for="(o, i) in filtered" x-bind:key="String(o.value) + i">
                <li role="option" x-bind:id="uid + '-' + i" x-bind:aria-selected="isSelected(o)" @mousemove="active = i" @click="pick(o)"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm transition-colors"
                    x-bind:class="[i === active && 'bg-surface-2', o.empty && 'text-muted']">
                    <span class="min-w-0 flex-1 truncate" x-bind:class="isSelected(o) && 'font-medium'" x-text="o.label"></span>
                    <span class="shrink-0 text-xs text-faint" x-show="o.hint" x-text="o.hint"></span>
                    <span class="size-4 shrink-0 text-accent" x-show="isSelected(o)"><x-ui.icon name="check" class="size-full" /></span>
                </li>
            </template>
            <li class="px-3 py-6 text-center text-sm text-faint" x-show="filtered.length === 0" x-text="s.t('dropdown.none')"></li>
        </ul>
    </div>
</div>

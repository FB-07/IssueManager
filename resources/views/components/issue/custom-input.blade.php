@props(['value', 'set', 'empty' => true])
{{-- The control of one project custom field `cf` (x-for scope): a list for enum fields, a typed input for numbers, texts, dates and periods.
     `value` is the JS expression of the current value, `set` the statement that stores `$value`. --}}
<template x-if="(cf.kind ?? 'enum') === 'enum'">
    <div>
        <x-ui.select value="{{ $value }}" select="{{ $set }}" options="cf.values.map((v) => ({ value: v, label: s.lbl('customValue', v) }))" :empty-label="$empty ? 's.t(\'card.none\')' : null" label="s.lbl('custom', cf.key)" />
    </div>
</template>
<template x-if="(cf.kind ?? 'enum') !== 'enum'">
    <input class="field" x-bind:type="({ number: 'number', date: 'date' })[cf.kind] ?? 'text'" x-bind:step="cf.kind === 'number' ? 'any' : null" maxlength="255"
           x-bind:placeholder="cf.kind === 'period' ? '2h 30m' : ''" x-bind:aria-label="s.lbl('custom', cf.key)"
           x-bind:value="{{ $value }}" @change="(($value) => { {{ $set }} })($event.target.value)">
</template>

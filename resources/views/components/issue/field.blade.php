@props(['field', 'required' => false, 'c' => 's'])
{{-- Labelled form row with required marker and (unconfirmed) suggestion chip --}}
<div {{ $attributes }}>
    <label for="field-{{ $field }}" class="label">
        <span x-text="s.t('fields.{{ $field }}')"></span>
        @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    <template x-if="!{{ $c }}.draft.{{ $field }} && {{ $c }}.draft.suggestions?.{{ $field }}">
        <div x-bind:title="{{ $c }}.draft.suggestions.count ? s.t('card.suggestion_history', { n: {{ $c }}.draft.suggestions.count }) : null" class="mt-2 flex flex-nowrap items-center gap-2 rounded-xl border border-warn/30 bg-warn-soft/40 py-1 pl-2.5 pr-1 text-xs">
            <span class="shrink-0 font-medium text-warn" x-text="s.t('card.suggestion')"></span>
            <span class="min-w-0 flex-1 truncate font-medium text-fg" x-text="{{ $field === 'assignee' ? $c.'.meta.assignees.find((a) => a.value === '.$c.'.draft.suggestions.assignee)?.label ?? '.$c.'.draft.suggestions.assignee' : $c.'.draft.suggestions.'.$field }}"></span>
            <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-accent px-2.5 py-1 font-medium text-accent-fg transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" @click="{{ $c }}.acceptSuggestion('{{ $field }}')">
                <x-ui.icon name="check" class="size-3.5" /><span x-text="s.t('card.accept')"></span>
            </button>
        </div>
    </template>
</div>

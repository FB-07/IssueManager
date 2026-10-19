@props(['field', 'required' => false])
{{-- Labelled form row with required marker and (unconfirmed) suggestion chip --}}
<div {{ $attributes }}>
    <label for="field-{{ $field }}" class="label">
        <span x-text="s.t('fields.{{ $field }}')"></span>
        @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
    </label>
    {{ $slot }}
    <template x-if="!s.draft.{{ $field }} && s.draft.suggestions?.{{ $field }}">
        <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-muted">
            <span class="badge bg-warn-soft text-warn" x-text="s.t('card.suggestion')"></span>
            <span class="font-medium text-fg" x-text="s.draft.suggestions.{{ $field }}"></span>
            <button type="button" class="font-medium text-accent hover:underline" @click="s.acceptSuggestion('{{ $field }}')" x-text="s.t('card.accept')"></button>
        </p>
    </template>
</div>

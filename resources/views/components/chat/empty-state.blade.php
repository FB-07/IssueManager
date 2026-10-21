<section x-show="s.page === 'chat' && !s.activeId && s.messages.length === 0 && !s.loadingConversation && !s.processing" x-cloak
         class="mx-auto flex min-h-full w-full max-w-2xl flex-col items-center justify-center px-5 py-10 text-center">
    <h2 class="anim-rise text-balance text-3xl font-semibold tracking-tight sm:text-4xl" x-text="s.t('empty.title')"></h2>
    <p class="anim-rise mt-3 max-w-lg text-balance text-[15px] leading-relaxed text-muted" x-text="s.t('empty.subtitle')"></p>

    <div class="anim-rise mt-9 grid w-full gap-2.5 sm:grid-cols-2">
        @foreach ([['bug', 'bug'], ['visual', 'palette'], ['technical', 'wrench'], ['feature', 'sparkles']] as [$key, $icon])
            <button type="button" @click="s.useSuggestion('{{ $key }}')"
                    class="group flex items-start gap-3 rounded-xl border border-line bg-surface px-4 py-3.5 text-left transition hover:border-line-strong hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-muted transition group-hover:text-accent"><x-ui.icon :name="$icon" /></span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium" x-text="s.t('empty.suggestions.{{ $key }}.title')"></span>
                    <span class="mt-0.5 block text-xs leading-snug text-muted" x-text="s.t('empty.suggestions.{{ $key }}.hint')"></span>
                </span>
            </button>
        @endforeach
    </div>
</section>

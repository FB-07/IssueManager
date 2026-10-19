<section x-show="s.activeId || s.messages.length > 0 || s.processing" x-cloak class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 sm:px-6">
    <div x-show="s.loadingConversation" class="flex justify-center py-10 text-muted"><x-ui.icon name="loader" class="anim-spin size-5" /></div>

    <template x-for="m in s.messages" x-bind:key="m.id">
        <div class="anim-rise">
            {{-- user --}}
            <template x-if="m.role === 'user'">
                <div class="flex flex-col items-end gap-2">
                    <div x-show="m.attachments?.length" class="flex max-w-[85%] flex-wrap justify-end gap-2">
                        <template x-for="a in m.attachments" x-bind:key="a.id">
                            <x-chat.attachment-view />
                        </template>
                    </div>
                    <div x-show="m.body" class="max-w-[85%] whitespace-pre-wrap break-words rounded-2xl rounded-tr-md bg-surface-2 px-4 py-2.5 text-[15px] leading-relaxed" x-text="m.body"></div>
                </div>
            </template>

            {{-- assistant --}}
            <template x-if="m.role === 'assistant' && m.kind !== 'creation-result'">
                <div class="flex gap-3">
                    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent"><x-ui.icon name="logo" class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="prose-msg text-[15px]" x-html="s.render(m.body)"></div>
                        <template x-if="m.id === s.lastCardId && s.draft && (m.meta?.card || s.draft.status === 'error')">
                            <x-issue.card />
                        </template>
                        <template x-if="m.meta?.card && m.id !== s.lastCardId">
                            <p class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 py-1 text-xs text-muted">
                                <x-ui.icon name="file" class="size-3.5" />
                                <span x-text="s.t('card.earlier_revision', { n: m.meta.revision })"></span>
                            </p>
                        </template>
                    </div>
                </div>
            </template>

            {{-- creation result --}}
            <template x-if="m.kind === 'creation-result'">
                <x-issue.result />
            </template>
        </div>
    </template>

    {{-- processing indicator --}}
    <div x-show="(s.processing && !s.streaming) || s.creating" x-transition.opacity class="flex gap-3" role="status" x-bind:aria-label="s.creating ? s.t(s.creatingKind === 'update' ? 'chat.updating' : 'chat.creating') : s.t('chat.processing')">
        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent"><x-ui.icon name="logo" class="size-4" /></span>
        <div class="flex items-center gap-2 py-1.5 text-sm text-muted">
            <span class="flex gap-1"><i class="dot size-1.5 rounded-full bg-muted"></i><i class="dot size-1.5 rounded-full bg-muted"></i><i class="dot size-1.5 rounded-full bg-muted"></i></span>
            <span x-text="s.creating ? s.t(s.creatingKind === 'update' ? 'chat.updating' : 'chat.creating') : s.t('chat.processing')"></span>
        </div>
    </div>
</section>

<section x-show="s.page === 'chat' && (s.activeId || s.messages.length > 0 || s.processing)" x-cloak class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 sm:px-6">
    {{-- a conversation opening: its messages, as placeholders --}}
    <div x-show="s.loadingConversation" x-cloak class="space-y-6" role="status" aria-busy="true">
        <span class="sr-only" x-text="s.t('common.loading')"></span>
        <div class="flex justify-end" aria-hidden="true"><span class="skeleton h-10 w-2/5 rounded-2xl"></span></div>
        <div class="flex gap-3" aria-hidden="true">
            <span class="skeleton size-7 shrink-0 rounded-lg"></span>
            <span class="min-w-0 flex-1 space-y-2.5"><span class="skeleton block h-3.5 w-11/12"></span><span class="skeleton block h-3.5 w-4/5"></span><span class="skeleton block h-3.5 w-2/3"></span></span>
        </div>
        <div class="flex justify-end" aria-hidden="true"><span class="skeleton h-10 w-1/3 rounded-2xl"></span></div>
        <div class="flex gap-3" aria-hidden="true">
            <span class="skeleton size-7 shrink-0 rounded-lg"></span>
            <span class="min-w-0 flex-1 space-y-2.5"><span class="skeleton block h-3.5 w-5/6"></span><span class="skeleton block h-3.5 w-3/4"></span></span>
        </div>
    </div>

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
                    {{-- the issues attached to this message (like its files: they belong to this message only) --}}
                    <div x-show="s.issuesOf(m).length" class="flex max-w-[85%] flex-wrap justify-end gap-1.5">
                        <template x-for="i in s.issuesOf(m)" x-bind:key="i.key">
                            <a x-bind:href="s.issueUrl(i.key)" target="_blank" rel="noopener" x-bind:title="i.summary"
                               class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-0.5 text-[11px] text-muted transition hover:text-fg">
                                <x-ui.icon name="check-circle" class="size-3 shrink-0" />
                                <span class="shrink-0 font-mono" x-text="i.key"></span>
                                <span class="truncate" x-text="i.summary"></span>
                            </a>
                        </template>
                    </div>
                    <div x-show="m.body" class="max-w-[85%] break-words rounded-2xl rounded-tr-md bg-surface-2 px-4 py-2.5 text-[15px] leading-relaxed">
                        <blockquote x-show="s.splitQuote(m.body).quote" class="mb-1.5 line-clamp-4 whitespace-pre-line border-l-2 border-accent pl-2.5 text-[13px] text-muted" x-text="s.splitQuote(m.body).quote"></blockquote>
                        <span class="whitespace-pre-wrap" x-text="s.splitQuote(m.body).text"></span>
                    </div>
                </div>
            </template>

            {{-- assistant --}}
            <template x-if="m.role === 'assistant' && m.kind !== 'creation-result'">
                <div class="flex gap-3">
                    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent"><x-ui.icon name="logo" class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="prose-msg text-[15px]" x-html="s.render(m.body)"></div>
                        {{-- the answer is on its way but paused (Claude is looking something up) --}}
                        <div x-show="s.stalled && m.id === s.messages.at(-1)?.id && String(m.id).startsWith('tmp-')" x-cloak class="mt-1 flex items-center py-1" role="status" x-bind:aria-label="s.t('chat.processing')">
                            <span class="flex gap-1"><i class="dot size-1.5 rounded-full bg-muted"></i><i class="dot size-1.5 rounded-full bg-muted"></i><i class="dot size-1.5 rounded-full bg-muted"></i></span>
                            <span class="ml-2 text-xs tabular-nums text-faint" x-text="s.elapsedLabel()"></span>
                        </div>
                        <template x-if="m.meta?.batch">
                            <x-chat.batch />
                        </template>
                        <template x-if="m.id === s.lastCardId && s.draft && (m.meta?.card || s.draft.status === 'error')">
                            <x-issue.card />
                        </template>
                        <template x-if="m.id === s.messages.at(-1)?.id && s.quickReplies.length && !m.meta?.batch">
                            <x-chat.quick-replies />
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

    {{-- waiting for the answer: three dots, in the place where it will appear --}}
    <div x-show="(s.processing && !s.streaming) || s.creating" x-transition.opacity class="flex gap-3" role="status" x-bind:aria-label="s.creating ? s.creatingText : s.t('chat.processing')">
        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent"><x-ui.icon name="logo" class="size-4" /></span>
        <div class="flex items-center gap-2.5 py-2 text-sm text-muted">
            <span class="flex gap-1.5"><i class="dot size-2 rounded-full bg-muted"></i><i class="dot size-2 rounded-full bg-muted"></i><i class="dot size-2 rounded-full bg-muted"></i></span>
            {{-- only creating/updating an issue says what is going on; thinking is just the dots --}}
            <span x-show="s.creating" x-text="s.creating ? s.creatingText : ''"></span>
            <span x-show="s.processing" class="text-xs tabular-nums text-faint" x-text="s.t('chat.preparing', { time: s.elapsedLabel() })"></span>
        </div>
    </div>

    {{-- offered above a selected passage of an answer --}}
    <button type="button" x-show="s.selection" x-cloak x-transition.opacity
            x-bind:style="s.selection && `left:${s.selection.x}px;top:${s.selection.y}px`"
            class="fixed z-40 -translate-x-1/2 -translate-y-full rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium shadow-[var(--shadow)] transition hover:bg-surface-2"
            @mousedown.prevent @touchstart.prevent="s.quoteSelection()" @click="s.quoteSelection()" x-text="s.t('composer.reply')"></button>
</section>

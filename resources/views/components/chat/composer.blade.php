<div class="shrink-0 px-3 pb-3 pt-1 sm:px-6 sm:pb-4" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
    <form class="relative mx-auto w-full max-w-3xl" @submit.prevent="s.send()">
        {{-- an issue key typed in the box: offer to attach that issue to the message (never done on its own) --}}
        <div x-show="s.mentionedKey" x-cloak class="anim-rise mb-2 flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-xs">
            <x-ui.icon name="check-circle" class="size-3.5 shrink-0 text-muted" />
            <span class="min-w-0 flex-1 truncate text-muted" x-text="s.t('context.mention', { key: s.mentionedKey })"></span>
            <button type="button" class="btn px-2.5 py-1 text-xs" @click="s.attachMentioned()" x-text="s.t('context.mention_link')"></button>
            <button type="button" class="btn-icon size-6" @click="s.dismissMentioned()" x-bind:aria-label="s.t('common.close')"><x-ui.icon name="x" class="size-3" /></button>
        </div>

        {{-- quick search for an issue to attach to the message: by key (DEV-12) or by words, with the recent ones before typing --}}
        <div x-show="s.contextPicker.open" x-cloak @click.outside="s.closeContextPicker()" @keydown.escape.window="s.closeContextPicker()"
             class="anim-rise absolute bottom-full left-0 z-30 mb-2 w-full max-w-lg overflow-hidden rounded-2xl border border-line bg-surface shadow-[var(--shadow)]" role="dialog" x-bind:aria-label="s.t('context.pick_hint')">
            <div class="flex items-center gap-2 border-b border-line px-3.5 py-2.5">
                <x-ui.icon name="search" class="size-4 shrink-0 text-muted" />
                <input id="context-search" type="text" autocomplete="off" maxlength="200" class="min-w-0 flex-1 bg-transparent text-sm placeholder:text-faint focus:outline-none"
                       x-bind:placeholder="s.t('context.search_placeholder')" x-model="s.contextPicker.q" @input="s.searchContext()"
                       @keydown.arrow-down.prevent="s.moveContext(1)" @keydown.arrow-up.prevent="s.moveContext(-1)" @keydown.enter.prevent="s.pickActiveContext()">
                <x-ui.icon name="loader" class="anim-spin size-4 shrink-0 text-muted" x-show="s.contextPicker.loading" />
            </div>
            <p class="px-3.5 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wider text-faint" x-show="!s.contextPicker.q.trim() && s.contextRecents.length" x-text="s.t('context.recent')"></p>
            <ul class="max-h-72 overflow-y-auto p-1.5" role="listbox" x-show="s.contextItems.length">
                <template x-for="(i, n) in s.contextItems" x-bind:key="i.id">
                    <li role="option" x-bind:aria-selected="n === s.contextPicker.active">
                        <button type="button" class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-surface-2" x-bind:class="n === s.contextPicker.active && 'bg-surface-2'" @click="s.pickContextIssue(i)" @mouseenter="s.contextPicker.active = n">
                            <span class="w-24 shrink-0 truncate font-mono text-xs text-muted" x-text="i.id"></span>
                            <span class="min-w-0 flex-1 truncate text-sm" x-text="i.summary"></span>
                            <span class="chip hidden shrink-0 sm:inline-flex" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                        </button>
                    </li>
                </template>
            </ul>
            <p class="px-3.5 py-3 text-sm text-faint" x-show="s.contextPicker.q.trim() && !s.contextPicker.loading && !s.contextPicker.results.length && !s.contextPicker.error" x-text="s.t('context.no_results')"></p>
            <p class="px-3.5 py-3 text-sm text-danger" x-show="s.contextPicker.error" x-text="s.contextPicker.error"></p>
            <button type="button" class="flex w-full items-center gap-2 border-t border-line px-3.5 py-2.5 text-left text-xs font-medium text-muted transition hover:bg-surface-2 hover:text-fg" @click="s.openIssuePicker()"><x-ui.icon name="filter" class="size-3.5" /><span x-text="s.t('context.advanced')"></span></button>
        </div>
        <div class="rounded-2xl border border-line bg-surface shadow-sm transition focus-within:border-line-strong focus-within:shadow-[var(--shadow)]">

            {{-- messages waiting for the current answer to finish --}}
            <div x-show="s.queue.length" x-cloak class="flex flex-col gap-1.5 px-3 pt-3">
                <template x-for="(q, i) in s.queue" x-bind:key="i">
                    <div class="flex items-center gap-2 rounded-xl border border-dashed border-line bg-surface-2/60 px-3 py-1.5 text-xs text-muted">
                        <span class="size-3.5 shrink-0" x-html="s.icon('loader')"></span>
                        <span class="shrink-0 font-medium" x-text="s.t('composer.queued')"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="q.body || q.files.map((f) => f.name).join(', ')"></span>
                        <button type="button" class="btn-icon size-6" @click="s.queue.splice(i, 1)" x-bind:aria-label="s.t('common.cancel')"><x-ui.icon name="x" class="size-3" /></button>
                    </div>
                </template>
            </div>

            {{-- the passage of an answer this message replies to --}}
            <div x-show="s.composer.quote" x-cloak class="px-3 pt-3">
                <div class="flex items-start gap-2 rounded-xl border-l-2 border-accent bg-surface-2 px-3 py-1.5 text-xs text-muted">
                    <x-ui.icon name="message" class="mt-0.5 size-3.5 shrink-0" />
                    <span class="line-clamp-3 min-w-0 flex-1 whitespace-pre-line" x-text="s.composer.quote"></span>
                    <button type="button" class="btn-icon size-6" @click="s.composer.quote = ''" x-bind:aria-label="s.t('common.close')"><x-ui.icon name="x" class="size-3" /></button>
                </div>
            </div>

            {{-- attachments preview: files and issues, the same way (they go with this message only) --}}
            <div x-show="s.composer.files.length || s.attachedIssues.length" class="flex flex-wrap gap-2 px-3 pt-3">
                <template x-for="i in s.attachedIssues" x-bind:key="i.key">
                    <div class="group relative flex max-w-full items-center gap-2 rounded-xl border border-line bg-surface-2 py-1.5 pl-1.5 pr-8 text-xs">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent"><x-ui.icon name="check-circle" class="size-4" /></span>
                        <span class="min-w-0">
                            <span class="flex items-center gap-1.5">
                                <a class="font-mono font-medium hover:underline" x-bind:href="i.url" target="_blank" rel="noopener" x-bind:title="s.t('similar.open')" x-text="i.key"></a>
                                <span class="text-faint" x-show="i.state" x-text="s.lbl('state', i.state)"></span>
                            </span>
                            <span class="block max-w-[16rem] truncate" x-bind:title="i.summary" x-text="i.summary"></span>
                        </span>
                        <button type="button" class="absolute right-1.5 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded-full text-muted transition hover:bg-surface-3 hover:text-fg" @click="s.removeIssue(i.key)" x-bind:aria-label="s.t('composer.remove_attachment', { name: i.key })"><x-ui.icon name="x" class="size-3" /></button>
                    </div>
                </template>
                <template x-for="f in s.composer.files" x-bind:key="f.id">
                    <div class="group relative flex items-center gap-2 rounded-xl border border-line bg-surface-2 py-1.5 pl-1.5 pr-8 text-xs">
                        <template x-if="f.preview"><img x-bind:src="f.preview" x-bind:alt="f.name" class="size-9 rounded-lg object-cover"></template>
                        <template x-if="!f.preview"><span class="flex size-9 items-center justify-center rounded-lg bg-surface-3 text-muted"><x-ui.icon name="file" /></span></template>
                        <span class="min-w-0"><span class="block max-w-[9rem] truncate font-medium" x-text="f.name"></span><span class="text-faint" x-text="s.size(f.size)"></span></span>
                        <button type="button" class="absolute right-1.5 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded-full text-muted transition hover:bg-surface-3 hover:text-fg" @click="s.removeFile(f.id)" x-bind:aria-label="s.t('composer.remove_attachment', { name: f.name })"><x-ui.icon name="x" class="size-3" /></button>
                    </div>
                </template>
            </div>

            <textarea id="composer-input" rows="1" maxlength="20000"
                      class="block max-h-[220px] w-full resize-none bg-transparent px-4 pb-1 pt-3.5 text-[15px] leading-relaxed placeholder:text-faint focus:outline-none"
                      x-bind:placeholder="s.voice.listening ? s.t('composer.voice_listening') : s.t('composer.placeholder')" x-bind:aria-label="s.t('composer.placeholder')"
                      x-model="s.composer.text" @input="s.autosize($el)" @keydown="s.onComposerKey($event)" @paste="s.onPaste($event)"
                      x-effect="s.composer.text; $nextTick(() => s.autosize($el))"></textarea>

            <div class="flex items-center gap-1 px-2 pb-2">
                <input type="file" id="file-input" class="sr-only" multiple tabindex="-1"
                       @change="s.addFiles($el.files); $el.value = ''">
                <div class="relative">
                    <button type="button" class="btn-icon" @click.stop="s.attachMenu = !s.attachMenu" aria-haspopup="menu" x-bind:aria-expanded="s.attachMenu" x-bind:aria-label="s.t('composer.attach')" x-bind:title="s.t('composer.attach')"><x-ui.icon name="paperclip" class="size-[18px]" /></button>
                    <div x-show="s.attachMenu" x-cloak @click.outside="s.attachMenu = false" @keydown.escape.window="s.attachMenu = false" role="menu"
                         class="anim-rise absolute bottom-full left-0 z-30 mb-2 w-64 rounded-2xl border border-line bg-surface p-1.5 text-sm shadow-[var(--shadow)]">
                        <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left transition hover:bg-surface-2" @click="s.attachMenu = false; document.getElementById('file-input').click()">
                            <x-ui.icon name="paperclip" class="size-4 text-muted" /><span x-text="s.t('attach.upload')"></span>
                        </button>
                        <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left transition hover:bg-surface-2" @click="s.openContextPicker()">
                            <x-ui.icon name="check-circle" class="size-4 text-muted" /><span x-text="s.t('attach.issue')"></span>
                        </button>
                    </div>
                </div>

                <button type="button" role="switch" x-bind:aria-checked="s.draftEnabled" @click="s.draftEnabled = !s.draftEnabled"
                        class="ml-1 inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-medium transition"
                        x-bind:class="s.draftEnabled ? 'border-accent/40 bg-accent-soft text-accent' : 'border-line text-muted hover:text-fg'"
                        x-bind:title="s.draftEnabled ? s.t('composer.draft_toggle_on') : s.t('composer.draft_toggle_off')">
                    <x-ui.icon name="file" class="size-3.5" />
                    <span x-text="s.t('composer.draft_toggle')"></span>
                    <span class="relative h-4 w-7 rounded-full transition" x-bind:class="s.draftEnabled ? 'bg-accent' : 'bg-surface-3'" aria-hidden="true">
                        <span class="absolute top-0.5 size-3 rounded-full transition-all" x-bind:class="s.draftEnabled ? 'left-3.5 bg-accent-fg' : 'left-0.5 bg-muted'"></span>
                    </span>
                </button>

                <span class="ml-auto mr-2 hidden text-[11px] text-faint sm:inline" x-text="s.t('composer.hint')"></span>

                <button type="button" class="btn-icon relative" x-show="s.voice.supported" @click="s.toggleVoice()" x-bind:disabled="s.processing"
                        x-bind:aria-pressed="s.voice.listening" x-bind:aria-label="s.voice.listening ? s.t('composer.voice_stop') : s.t('composer.voice_start')" x-bind:title="s.voice.listening ? s.t('composer.voice_stop') : s.t('composer.voice_start')"
                        x-bind:class="s.voice.listening && 'bg-danger-soft text-danger'">
                    <x-ui.icon name="mic" class="size-[18px]" />
                    <span class="absolute right-1.5 top-1.5 size-2 animate-ping rounded-full bg-danger" x-show="s.voice.listening" aria-hidden="true"></span>
                </button>

                <button type="submit" class="flex size-9 items-center justify-center rounded-xl bg-accent text-accent-fg transition hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-40"
                        x-bind:disabled="!s.canSend" x-bind:aria-label="s.t('composer.send')" x-bind:title="s.t('composer.send')">
                    <span class="size-[18px]" x-html="s.icon(s.processing && !s.canSend ? 'loader' : 'send')" x-bind:class="s.processing && !s.canSend && 'anim-spin'"></span>
                </button>
            </div>
        </div>
        <p class="mt-2 text-center text-[11px] text-faint" x-text="s.t('composer.disclaimer')"></p>
    </form>
</div>

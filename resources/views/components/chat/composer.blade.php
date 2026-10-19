<div class="shrink-0 px-3 pb-3 pt-1 sm:px-6 sm:pb-4" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
    <form class="mx-auto w-full max-w-3xl" @submit.prevent="s.send()">
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

            {{-- attachments preview --}}
            <div x-show="s.composer.files.length" class="flex flex-wrap gap-2 px-3 pt-3">
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
                       accept="image/*,.pdf,.txt,.md,.log,.json,.csv,.zip,.doc,.docx,.xls,.xlsx,.php,.js,.ts,.css,.html,.vue,.sql,.yml,.yaml"
                       @change="s.addFiles($el.files); $el.value = ''">
                <button type="button" class="btn-icon" @click="document.getElementById('file-input').click()" x-bind:aria-label="s.t('composer.attach')" x-bind:title="s.t('composer.attach')"><x-ui.icon name="paperclip" class="size-[18px]" /></button>

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

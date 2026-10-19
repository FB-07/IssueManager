{{-- Clickable answers under the last assistant message; clicking sends the text as the user's message --}}
<div class="anim-rise mt-3 flex flex-wrap gap-2" role="group" x-bind:aria-label="s.t('chat.quick_replies')">
    <template x-for="(r, i) in s.quickReplies" x-bind:key="i">
        <button type="button" @click="s.pickReply(r)"
                class="inline-flex max-w-full items-center gap-2 rounded-full border border-line bg-surface px-3.5 py-1.5 text-sm text-fg transition hover:border-line-strong hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
            <x-ui.avatar of="r.option" />
            <span class="size-3.5 shrink-0 text-muted" x-show="r.kind === 'action'" aria-hidden="true"><x-ui.icon name="search" class="size-full" /></span>
            <span class="truncate" x-text="r.label"></span>
        </button>
    </template>
</div>

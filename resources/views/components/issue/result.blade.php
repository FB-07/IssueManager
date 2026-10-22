{{-- Creation result for message `m` (x-for scope) --}}
<div class="flex gap-3">
    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-success-soft text-success"><x-ui.icon name="check-circle" class="size-4" /></span>
    <div class="min-w-0 flex-1 rounded-2xl border border-success/30 bg-surface p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-sm font-semibold" x-text="m.meta.commented ? s.t(m.meta.edited ? 'result.comment_edited' : 'result.commented') : (m.meta.updated ? s.t('result.updated_live') : s.t('result.created_live'))"></h3>
        </div>

        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
            <dt class="text-muted" x-text="s.t('result.id')"></dt>
            <dd class="font-mono font-medium" x-text="m.meta.key"></dd>
            <dt class="text-muted" x-text="s.t('result.link')"></dt>
            <dd class="min-w-0">
                    <a x-bind:href="m.meta.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-accent hover:underline"><span x-text="m.meta.key"></span><x-ui.icon name="external" class="size-3.5" /></a>
            </dd>
            <dt class="text-muted" x-show="m.meta.changes?.length" x-text="s.t('result.changes')"></dt>
            <dd class="flex flex-wrap gap-1.5" x-show="m.meta.changes?.length">
                <template x-for="f in (m.meta.changes ?? [])" x-bind:key="f"><span class="chip" x-text="s.t('fields.' + f) === 'fields.' + f ? f : s.t('fields.' + f)"></span></template>
            </dd>
            <dt class="text-muted" x-show="m.meta.comment" x-text="s.t('result.comment')"></dt>
            <dd class="min-w-0" x-show="m.meta.comment">
                <div x-show="!m.editing">
                    <div class="whitespace-pre-wrap break-words rounded-lg bg-surface-2 px-3 py-2" x-text="m.meta.comment"></div>
                    <div class="mt-1.5 flex items-center gap-3 text-xs">
                        <span class="text-muted" x-show="m.meta.edited" x-text="s.t('result.edited')"></span>
                        <button type="button" class="text-accent hover:underline" x-show="m.meta.comment_id" x-bind:disabled="s.creating" @click="m.editText = m.meta.comment; m.editing = true" x-text="s.t('result.edit_comment')"></button>
                    </div>
                </div>
                <div x-show="m.editing" x-cloak>
                    <textarea rows="4" maxlength="10000" class="w-full rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm" x-model="m.editText" @keydown.escape="m.editing = false"></textarea>
                    <div class="mt-1.5 flex gap-2 text-xs">
                        <button type="button" class="btn-primary" x-bind:disabled="s.creating || !(m.editText ?? '').trim()" @click="await s.saveComment(m); m.editing = false" x-text="s.t('result.save_comment')"></button>
                        <button type="button" class="text-muted hover:underline" @click="m.editing = false" x-text="s.t('result.cancel')"></button>
                    </div>
                </div>
            </dd>
            <dt class="text-muted" x-text="s.t('result.summary')"></dt>
            <dd class="break-words" x-text="m.meta.summary"></dd>
        </dl>

        <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs" x-show="m.meta.attachments?.length">
            <span class="text-muted" x-text="s.t('result.attachments')"></span>
            <template x-for="n in m.meta.attachments" x-bind:key="n"><span class="chip"><x-ui.icon name="paperclip" class="size-3" /><span x-text="n"></span></span></template>
        </div>
        <p class="mt-2 flex items-start gap-1.5 text-xs text-warn" x-show="m.meta.attachments_failed?.length">
            <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span x-text="s.t('result.attachments_failed', { names: (m.meta.attachments_failed ?? []).join(', ') })"></span>
        </p>

        {{-- read-only snapshot of what was sent --}}
        <details class="mt-4 rounded-xl border border-line bg-surface-2/50" x-bind:open="m.meta.direct" x-show="m.meta.preview">
            <summary class="cursor-pointer select-none px-3.5 py-2.5 text-sm font-medium" x-text="s.t('result.preview')"></summary>
            <div class="space-y-3 border-t border-line p-3.5">
                <h4 class="text-base font-semibold leading-snug" x-text="m.meta.summary"></h4>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <span class="chip" x-show="m.meta.preview?.project" x-text="m.meta.preview?.project"></span>
                    <span class="chip" x-show="m.meta.preview?.type" x-text="s.lbl('type', m.meta.preview?.type)"></span>
                    <span class="chip" x-show="m.meta.preview?.priority" x-text="s.lbl('priority', m.meta.preview?.priority)"></span>
                    <span class="chip" x-show="m.meta.preview?.state" x-text="s.lbl('state', m.meta.preview?.state)"></span>
                    <span class="chip" x-show="m.meta.preview?.subsystem" x-text="s.lbl('subsystem', m.meta.preview?.subsystem)"></span>
                    <span class="chip" x-show="m.meta.preview?.assignee" x-text="s.meta.assignees.find((a) => a.value === m.meta.preview?.assignee)?.label ?? m.meta.preview?.assignee"></span>
                    <template x-for="(v, k) in (m.meta.preview?.custom_fields ?? {})" x-bind:key="k"><span class="chip"><span x-text="s.lbl('custom', k)"></span>: <span x-text="s.lbl('customValue', v)"></span></span></template>
                    <template x-for="l in (m.meta.preview?.labels ?? [])" x-bind:key="l"><span class="chip"><x-ui.icon name="tag" class="size-3" /><span x-text="l"></span></span></template>
                </div>
                <div class="prose-msg text-sm" x-html="s.render(m.meta.preview?.description)"></div>
            </div>
        </details>
    </div>
</div>

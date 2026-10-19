{{-- Creation result for message `m` (x-for scope) --}}
<div class="flex gap-3">
    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-success-soft text-success"><x-ui.icon name="check-circle" class="size-4" /></span>
    <div class="min-w-0 flex-1 rounded-2xl border border-success/30 bg-surface p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-sm font-semibold" x-text="m.meta.demo ? (m.meta.updated ? s.t('result.updated') : s.t('result.created')) : (m.meta.updated ? s.t('result.updated_live') : s.t('result.created_live'))"></h3>
            <span class="badge bg-warn-soft text-warn" x-show="m.meta.demo" x-text="s.t('result.demo_badge')"></span>
        </div>

        <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
            <dt class="text-muted" x-text="s.t('result.id')"></dt>
            <dd class="font-mono font-medium" x-text="m.meta.key"></dd>
            <dt class="text-muted" x-text="s.t('result.link')"></dt>
            <dd class="min-w-0">
                <template x-if="m.meta.demo">
                    <span class="inline-flex flex-wrap items-center gap-2">
                        <span class="inline-flex cursor-not-allowed items-center gap-1 font-mono text-faint line-through decoration-faint/50" aria-disabled="true">
                            <span x-text="m.meta.key"></span><x-ui.icon name="external" class="size-3.5" />
                        </span>
                        <span class="text-xs text-warn" x-text="s.t('result.link_demo')"></span>
                    </span>
                </template>
                <template x-if="!m.meta.demo">
                    <a x-bind:href="m.meta.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-accent hover:underline"><span x-text="m.meta.key"></span><x-ui.icon name="external" class="size-3.5" /></a>
                </template>
            </dd>
            <dt class="text-muted" x-show="m.meta.changes?.length" x-text="s.t('result.changes')"></dt>
            <dd class="flex flex-wrap gap-1.5" x-show="m.meta.changes?.length">
                <template x-for="f in (m.meta.changes ?? [])" x-bind:key="f"><span class="chip" x-text="s.t('fields.' + f) === 'fields.' + f ? f : s.t('fields.' + f)"></span></template>
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

        <p class="mt-3 text-xs text-muted" x-show="m.meta.demo" x-text="s.t('result.demo_note')"></p>

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

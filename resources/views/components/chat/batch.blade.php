{{-- Issues proposed from one pasted text, for message `m` (x-for scope): decided one by one (approve / reject buttons),
     accepted ones collect in a carousel; nothing is created until every proposal is approved or rejected. --}}
<div class="mt-4 space-y-4" x-data="{ b: s.batchState(m) }">

    {{-- current proposal (or an accepted one opened for editing) --}}
    <template x-if="b.viewing !== null || s.batchCursor(m) !== null">
        <div x-data="{
                fly: 0, busy: false,
                get ci() { return b.viewing ?? s.batchCursor(m) },
                get left() { return s.batchPending(m).filter((r) => !r.decision).length },
                // the card is thrown out by itself (right = approved, left = rejected), then the next one rises into place
                async go(decision) {
                    if (this.busy) return;
                    this.busy = true;
                    if (!(await s.batchCanDecide(m, this.ci, decision))) {
                        this.busy = false;
                        this.$nextTick(() => this.$refs.deck?.querySelector('[data-similar]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                        return;
                    }
                    this.fly = decision === 'accepted' ? 1 : -1;
                    await new Promise((r) => setTimeout(r, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 380));
                    s.batchDecide(m, this.ci, decision);
                    this.fly = 0;
                    this.busy = false;
                    this.$nextTick(() => this.$refs.deck?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
                },
             }" x-effect="s.batchWatchSimilar(m, ci)">
            <div class="relative pb-4">
                {{-- cards waiting underneath --}}
                <template x-if="b.viewing === null && left > 1">
                    <div class="pointer-events-none absolute inset-x-5 bottom-1 top-6 rounded-2xl border border-line bg-surface-2 opacity-70"></div>
                </template>
                <template x-if="b.viewing === null && left > 2">
                    <div class="pointer-events-none absolute inset-x-9 -bottom-1 top-10 rounded-2xl border border-line bg-surface-3 opacity-40"></div>
                </template>

                <div x-ref="deck" class="relative origin-bottom"
                     x-bind:style="{
                         transform: fly ? `translate3d(${fly * 125}%, -24px, 0) rotate(${fly * 12}deg) scale(.96)` : 'none',
                         opacity: fly ? 0 : 1,
                         transition: fly ? 'transform .38s cubic-bezier(.5, 0, .75, .4), opacity .38s ease-in .08s' : 'none',
                         willChange: fly ? 'transform, opacity' : 'auto',
                     }">
                    {{-- soft green/red tint while the card leaves --}}
                    <div class="pointer-events-none absolute inset-0 z-10 rounded-2xl transition-opacity duration-200"
                         x-bind:class="fly === 1 ? 'bg-success/10 opacity-100' : (fly === -1 ? 'bg-danger/10 opacity-100' : 'opacity-0')"></div>

                    <template x-for="k in [ci]" x-bind:key="k">
                        <div class="anim-deck-in">
                            <x-issue.card c="s.batchScope(m, k)" :batch="true" />
                        </div>
                    </template>
                </div>
            </div>

            {{-- buttons --}}
            <div class="mt-3 flex items-center justify-center gap-3">
                <template x-if="b.viewing === null">
                    <div class="flex items-center gap-3">
                        <button type="button" class="btn-icon size-10 border-line" x-show="b.history.length" x-bind:disabled="b.busy" x-bind:title="s.t('batch.back')" x-bind:aria-label="s.t('batch.back')" @click="s.batchBack(m)">
                            <x-ui.icon name="refresh" class="size-4" />
                        </button>
                        <button type="button" class="btn border-danger/50 px-5 text-danger hover:bg-danger/10" x-bind:disabled="b.busy || busy" @click="go('rejected')">
                            <x-ui.icon name="x" class="size-4" /><span x-text="s.t('batch.reject')"></span>
                        </button>
                        <button type="button" class="btn btn-primary bg-success px-5 hover:bg-success hover:opacity-90" x-bind:disabled="b.busy || busy" @click="go('accepted')">
                            <x-ui.icon name="check" class="size-4" /><span x-text="s.t('batch.approve')"></span>
                        </button>
                    </div>
                </template>
                <template x-if="b.viewing !== null">
                    <div class="flex items-center gap-3">
                        <button type="button" class="btn" @click="b.viewing = null" x-text="s.t('batch.close')"></button>
                        <button type="button" class="btn border-danger/50 text-danger hover:bg-danger/10" x-bind:disabled="b.busy" @click="s.batchDecide(m, b.viewing, 'rejected')">
                            <x-ui.icon name="x" class="size-4" /><span x-text="s.t('batch.reject')"></span>
                        </button>
                    </div>
                </template>
            </div>
            <p class="mt-2 text-center text-xs text-muted" x-show="b.viewing === null" x-text="s.t('batch.remaining', { n: s.batchPending(m).filter((r) => !r.decision).length })"></p>
        </div>
    </template>

    {{-- every proposal decided: summary and the one creation button --}}
    <template x-if="b.viewing === null && s.batchCursor(m) === null">
        <div class="anim-rise flex flex-wrap items-center gap-3 rounded-2xl border border-line bg-surface px-4 py-3.5 shadow-sm sm:px-5">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent"><x-ui.icon name="check-circle" class="size-[18px]" /></span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold" x-text="s.batchPending(m).length ? s.t('batch.all_reviewed') : s.t('batch.finished')"></h3>
                <p class="mt-0.5 text-xs text-muted" x-text="s.t('batch.summary', { a: s.batchAccepted(m).length, r: b.rows.filter((r) => r.decision === 'rejected').length })"></p>
            </div>
            <button type="button" class="btn-icon size-9 border-line" x-show="b.history.length && s.batchPending(m).length" x-bind:disabled="b.busy" x-bind:title="s.t('batch.back')" x-bind:aria-label="s.t('batch.back')" @click="s.batchBack(m)">
                <x-ui.icon name="refresh" class="size-4" />
            </button>
            <button type="button" class="btn btn-primary" x-show="s.batchPending(m).length" x-bind:disabled="b.busy" @click="s.batchCreate(m)">
                <x-ui.icon name="loader" class="anim-spin size-4" x-show="b.busy" />
                <span x-text="b.busy ? s.t('batch.creating') : (s.batchToCreate(m).length ? s.t('batch.create', { n: s.batchToCreate(m).length }) : s.t('batch.finish'))"></span>
            </button>
        </div>
    </template>

    {{-- carousel of the accepted ones --}}
    <template x-if="s.batchAccepted(m).length">
        <section class="anim-rise" x-data="{ strip: null }" x-init="strip = $refs.strip">
            <div class="mb-2 flex items-center gap-2">
                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="s.t('batch.accepted_title', { n: s.batchAccepted(m).length })"></h4>
                <div class="ml-auto flex gap-1">
                    <button type="button" class="btn-icon size-7 border-line" x-bind:aria-label="s.t('batch.prev')" @click="$refs.strip.scrollBy({ left: -$refs.strip.clientWidth * .8, behavior: 'smooth' })"><x-ui.icon name="chevron-right" class="size-3.5 rotate-180" /></button>
                    <button type="button" class="btn-icon size-7 border-line" x-bind:aria-label="s.t('batch.next')" @click="$refs.strip.scrollBy({ left: $refs.strip.clientWidth * .8, behavior: 'smooth' })"><x-ui.icon name="chevron-right" class="size-3.5" /></button>
                </div>
            </div>
            <div x-ref="strip" class="flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <template x-for="a in s.batchAccepted(m)" x-bind:key="a.index">
                    <article class="w-[85%] shrink-0 snap-start rounded-2xl border bg-surface p-3.5 shadow-sm sm:w-72" x-bind:class="a.r.status === 'created' ? 'border-success/40' : (b.viewing === a.index ? 'border-accent' : 'border-line')">
                        <div class="flex items-center gap-2">
                            <span class="badge bg-surface-3 text-muted" x-text="s.t('batch.counter', { i: a.index + 1, n: b.rows.length })"></span>
                            <a class="badge bg-success-soft text-success hover:underline" x-show="a.r.status === 'created'" x-bind:href="a.r.issue_url" target="_blank" rel="noopener" x-text="a.r.issue_key"></a>
                        </div>
                        <h5 class="mt-2 line-clamp-2 text-sm font-semibold leading-snug" x-text="a.r.summary"></h5>
                        <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                            <span class="chip" x-show="a.r.project" x-text="a.r.project"></span>
                            <span class="chip" x-show="a.r.type" x-text="s.lbl('type', a.r.type)"></span>
                            <span class="chip" x-show="a.r.priority" x-text="s.lbl('priority', a.r.priority)"></span>
                        </div>
                        <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted" x-show="a.r.status === 'created' ? (a.r.attachments_sent ?? []).length : (a.r.attachments ?? []).length">
                            <x-ui.icon name="paperclip" class="size-3.5" />
                            <span x-text="s.t((a.r.status === 'created' ? a.r.attachments_sent.length : a.r.attachments.length) === 1 ? 'batch.attachments_one' : 'batch.attachments_count', { n: a.r.status === 'created' ? a.r.attachments_sent.length : a.r.attachments.length })"></span>
                        </p>
                        <p class="mt-1 flex items-start gap-1.5 text-xs text-warn" x-show="(a.r.attachments_failed ?? []).length">
                            <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span class="line-clamp-2" x-text="s.t('result.attachments_failed', { names: (a.r.attachments_failed ?? []).join(', ') })"></span>
                        </p>
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-danger" x-show="a.r.status === 'pending' && a.r.error" role="alert">
                            <x-ui.icon name="alert" class="mt-px size-3.5 shrink-0" /><span class="line-clamp-3" x-text="a.r.error"></span>
                        </p>
                        <div class="mt-3 flex gap-1.5" x-show="a.r.status === 'pending'">
                            <button type="button" class="btn px-2.5 py-1 text-xs" x-bind:disabled="b.busy" @click="b.viewing = b.viewing === a.index ? null : a.index; $nextTick(() => $el.closest('section').previousElementSibling?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))">
                                <x-ui.icon name="pencil" class="size-3.5" /><span x-text="s.t('batch.edit')"></span>
                            </button>
                            <button type="button" class="btn btn-ghost px-2.5 py-1 text-xs hover:text-danger" x-bind:disabled="b.busy" @click="s.batchDecide(m, a.index, 'rejected')">
                                <x-ui.icon name="x" class="size-3.5" /><span x-text="s.t('batch.reject')"></span>
                            </button>
                        </div>
                    </article>
                </template>
            </div>
        </section>
    </template>
</div>

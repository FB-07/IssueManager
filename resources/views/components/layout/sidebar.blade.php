<div x-show="s.sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-black/55 lg:hidden" @click="s.sidebarOpen = false"></div>

<aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-line bg-surface transition-[transform,width] duration-200 lg:static lg:translate-x-0"
       x-bind:class="[s.sidebarOpen && 'translate-x-0', s.sidebarCollapsed && 'lg:w-[68px]']"
       x-bind:aria-label="s.t('nav.sidebar')">

    {{-- brand --}}
    <div class="flex h-14 shrink-0 items-center gap-2.5 px-4">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center" x-bind:aria-label="s.t('app.name')" x-bind:title="s.t('app.name')" @click.prevent="s.newChat()">
            <img src="{{ asset('images/proside-logo.png') }}" alt="Proside" width="560" height="158" class="logo-adaptive h-7 w-auto select-none" x-bind:class="s.sidebarCollapsed && 'lg:hidden'" draggable="false">
            <img src="{{ asset('images/proside-mark.png') }}" alt="Proside" width="300" height="146" class="logo-adaptive hidden h-5 w-auto select-none" x-bind:class="s.sidebarCollapsed && 'lg:block'" draggable="false">
        </a>
        <button type="button" class="btn-icon ml-auto size-8 lg:hidden" @click="s.sidebarOpen = false" x-bind:aria-label="s.t('common.close')"><x-ui.icon name="x" /></button>
    </div>

    {{-- actions --}}
    <div class="space-y-1 px-3 pb-2">
        <button type="button" class="btn group w-full justify-start bg-surface-2" x-bind:class="s.sidebarCollapsed && 'lg:justify-center lg:px-0'" @click="s.newChat()" x-bind:title="s.sidebarCollapsed ? s.t('nav.new_chat') : null">
            <x-ui.icon name="plus" class="shrink-0" />
            <span x-bind:class="s.sidebarCollapsed && 'lg:hidden'" x-text="s.t('nav.new_chat')"></span>
            <span class="ml-auto flex gap-1 text-xs text-faint opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" x-bind:class="s.sidebarCollapsed && 'lg:hidden'" aria-hidden="true"><kbd class="font-sans" x-text="s.altLabel"></kbd><kbd class="font-sans">N</kbd></span>
        </button>
        <button type="button" class="btn-ghost btn group w-full justify-start" x-bind:class="s.sidebarCollapsed && 'lg:justify-center lg:px-0'" @click="s.openIssues()" x-bind:title="s.sidebarCollapsed ? s.t('nav.issues') : null">
            <x-ui.icon name="check-circle" class="shrink-0" />
            <span x-bind:class="s.sidebarCollapsed && 'lg:hidden'" x-text="s.t('nav.issues')"></span>
            <span class="ml-auto flex gap-1 text-xs text-faint opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" x-bind:class="s.sidebarCollapsed && 'lg:hidden'" aria-hidden="true"><kbd class="font-sans" x-text="s.altLabel"></kbd><kbd class="font-sans">I</kbd></span>
        </button>
    </div>

    {{-- history --}}
    <nav class="min-h-0 flex-1 overflow-y-auto px-3 pb-3" x-bind:class="s.sidebarCollapsed && 'lg:hidden'">
        <p x-show="s.conversations.length === 0" class="px-2 py-3 text-sm text-faint" x-text="s.t('nav.history_empty')"></p>

        <div class="sticky top-0 z-10 -mx-3 bg-surface px-5 pb-1 pt-2" x-show="s.conversations.length > 0">
            <div class="relative flex items-center justify-between" x-show="!s.selectMode">
                <span class="text-[13px] font-semibold  uppercase tracking-wider text-muted" x-text="s.t('nav.history')"></span>
                <button type="button" class="btn-icon size-7" @click.stop="s.historyMenu = !s.historyMenu" aria-haspopup="menu" x-bind:aria-expanded="s.historyMenu" x-bind:aria-label="s.t('nav.options')" x-bind:title="s.t('nav.options')"><x-ui.icon name="more" class="size-4" /></button>
                <div x-show="s.historyMenu" x-cloak @click.outside="s.historyMenu = false" @keydown.escape.window="s.historyMenu = false" role="menu"
                     class="anim-rise absolute right-0 top-full z-30 mt-1 w-56 rounded-xl border border-line bg-surface p-1 text-sm shadow-[var(--shadow)]">
                    <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left transition hover:bg-surface-2" @click="s.startHistorySearch()">
                        <x-ui.icon name="search" class="size-4 text-muted" /><span x-text="s.t('nav.search_chats')"></span>
                    </button>
                    <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left transition hover:bg-surface-2" @click="s.startSelect()">
                        <x-ui.icon name="check" class="size-4 text-muted" /><span x-text="s.t('nav.select')"></span>
                    </button>
                </div>
            </div>
            <div class="mt-1.5 flex items-center gap-2 rounded-lg border border-line bg-surface-2 px-2.5" x-show="s.historySearching && !s.selectMode" x-cloak>
                <x-ui.icon name="search" class="size-3.5 shrink-0 text-muted" />
                <input id="history-search" type="text" autocomplete="off" maxlength="120" class="min-w-0 flex-1 bg-transparent py-1.5 text-sm placeholder:text-faint focus:outline-none"
                       x-bind:placeholder="s.t('nav.search_placeholder')" x-model="s.historyQuery" @keydown.escape.stop="s.stopHistorySearch()">
                <button type="button" class="btn-icon size-6" @click="s.stopHistorySearch()" x-bind:aria-label="s.t('common.close')"><x-ui.icon name="x" class="size-3" /></button>
            </div>
            <div class="flex items-center justify-between gap-2" x-show="s.selectMode" x-cloak>
                <button type="button" class="flex items-center gap-2 rounded-md py-0.5 text-xs font-medium text-muted transition hover:text-fg" role="checkbox" x-bind:aria-checked="s.allSelected" @click="s.toggleSelectAll()">
                    <span class="flex size-4 items-center justify-center rounded border transition" x-bind:class="s.allSelected ? 'border-accent bg-accent text-accent-fg' : s.selectedIds.length ? 'border-accent text-accent' : 'border-line-strong'">
                        <span class="size-3" x-show="s.allSelected" x-html="s.icon('check')"></span>
                        <span class="h-0.5 w-2 rounded bg-accent" x-show="s.selectedIds.length && !s.allSelected"></span>
                    </span>
                    <span x-text="s.selectedIds.length ? s.t('nav.selected', { n: s.selectedIds.length }) : s.t('nav.select_all')"></span>
                </button>
                <button type="button" class="rounded-md px-1.5 py-0.5 text-xs font-medium text-muted transition hover:bg-surface-2 hover:text-fg" @click="s.cancelSelect()" x-text="s.t('common.cancel')"></button>
            </div>
        </div>
        <p x-show="s.conversations.length && s.historyQuery.trim() && !s.groupedConversations.length" class="px-2 py-3 text-sm text-faint" x-text="s.t('nav.no_matches')"></p>
        <template x-for="g in s.groupedConversations" x-bind:key="g.key">
        <div>
        <p class="px-2 pb-1.5 pt-3 text-[11px] font-medium uppercase tracking-wider text-muted" x-text="s.t('nav.group_' + g.key)"></p>
        <ul class="space-y-0.5">
            <template x-for="c in g.items" x-bind:key="c.id">
                <li class="group relative">
                    <template x-if="s.renamingId === c.id">
                        <form class="px-1 py-0.5" @submit.prevent="s.renameConversation(c)">
                            <input class="field py-1.5" x-model="s.renameValue" x-init="$nextTick(() => $el.select())" @keydown.escape.stop="s.renamingId = null" @blur="s.renameConversation(c)" maxlength="120">
                        </form>
                    </template>
                    <template x-if="s.renamingId !== c.id">
                        <div class="flex items-center rounded-lg transition hover:bg-surface-2" x-bind:class="(s.selectMode ? s.isSelected(c.id) : s.activeId === c.id) && 'bg-surface-2'">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 px-2.5 py-2 text-left text-sm" @click="s.selectMode ? s.toggleSelect(c.id) : s.openConversation(c.id)" x-bind:aria-current="!s.selectMode && s.activeId === c.id ? 'page' : null" x-bind:role="s.selectMode ? 'checkbox' : null" x-bind:aria-checked="s.selectMode ? s.isSelected(c.id) : null">
                                <span class="flex size-4 shrink-0 items-center justify-center rounded border transition" x-show="s.selectMode" x-bind:class="s.isSelected(c.id) ? 'border-accent bg-accent text-accent-fg' : 'border-line-strong'">
                                    <span class="size-3" x-show="s.isSelected(c.id)" x-html="s.icon('check')"></span>
                                </span>
                                <span class="size-4 shrink-0 text-faint" x-show="!s.selectMode" x-html="s.icon('message')"></span>
                                <span class="truncate" x-text="c.title || s.t('nav.untitled')"></span>
                            </button>
                            <template x-if="s.deletingId !== c.id && !s.selectMode">
                                <span class="flex shrink-0 pr-1 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
                                    <button type="button" class="btn-icon size-7" @click="s.renamingId = c.id; s.renameValue = c.title" x-bind:aria-label="s.t('common.rename')"><x-ui.icon name="pencil" class="size-3.5" /></button>
                                    <button type="button" class="btn-icon size-7 hover:text-danger" @click="s.deletingId = c.id" x-bind:aria-label="s.t('common.delete')"><x-ui.icon name="trash" class="size-3.5" /></button>
                                </span>
                            </template>
                            <template x-if="s.deletingId === c.id && !s.selectMode">
                                <span class="flex shrink-0 items-center gap-0.5 pr-1">
                                    <button type="button" class="btn-icon size-7 text-danger" @click="s.deleteConversation(c)" x-bind:aria-label="s.t('common.confirm')"><x-ui.icon name="check" class="size-3.5" /></button>
                                    <button type="button" class="btn-icon size-7" @click="s.deletingId = null" x-bind:aria-label="s.t('common.cancel')"><x-ui.icon name="x" class="size-3.5" /></button>
                                </span>
                            </template>
                        </div>
                    </template>
                </li>
            </template>
        </ul>
        </div>
        </template>
    </nav>

    {{-- bulk delete --}}
    <div class="shrink-0 border-t border-line p-3" x-show="s.selectMode" x-cloak>
        <template x-if="!s.confirmBulk">
            <button type="button" class="btn btn-danger w-full" x-bind:disabled="!s.selectedIds.length" @click="s.confirmBulk = true">
                <x-ui.icon name="trash" /><span x-text="s.t('nav.delete_selected', { n: s.selectedIds.length })"></span>
            </button>
        </template>
        <template x-if="s.confirmBulk">
            <div class="space-y-2">
                <p class="text-xs text-muted" x-text="s.t('nav.delete_confirm', { n: s.selectedIds.length })"></p>
                <div class="flex gap-2">
                    <button type="button" class="btn flex-1" @click="s.confirmBulk = false" x-bind:disabled="s.bulkDeleting" x-text="s.t('common.cancel')"></button>
                    <button type="button" class="btn btn-danger flex-1" @click="s.deleteSelected()" x-bind:disabled="s.bulkDeleting" x-text="s.bulkDeleting ? s.t('common.loading') : s.t('common.delete')"></button>
                </div>
            </div>
        </template>
    </div>
    <div class="flex-1" x-bind:class="s.sidebarCollapsed ? 'hidden lg:block' : 'hidden'"></div>

    {{-- profile --}}
    <div class="shrink-0 border-t border-line p-3">
        <button type="button" class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition hover:bg-surface-2" x-bind:class="s.sidebarCollapsed && 'lg:justify-center lg:px-0'" @click="s.settingsOpen = true; s.sidebarOpen = false" x-bind:title="s.t('nav.settings')">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent" x-text="s.user.name.slice(0, 1)"></span>
            <span class="min-w-0 flex-1" x-bind:class="s.sidebarCollapsed && 'lg:hidden'">
                <span class="block truncate text-sm font-medium" x-text="s.user.name"></span>
                <span class="block truncate text-xs text-faint" x-text="s.t('nav.settings')"></span>
            </span>
            <x-ui.icon name="settings" class="shrink-0 text-faint" />
        </button>
    </div>
</aside>

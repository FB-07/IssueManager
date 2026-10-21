<header class="flex h-14 shrink-0 items-center gap-2 border-b border-line px-3 sm:px-4">
    <button type="button" class="btn-icon" @click="s.toggleSidebar()" x-bind:aria-label="s.t('nav.toggle_sidebar')" x-bind:title="s.t('nav.toggle_sidebar')" data-shortcut="alt+B">
        <x-ui.icon name="panel-left" class="size-[18px]" />
    </button>

    <h1 class="min-w-0 flex-1 truncate text-sm font-medium" x-text="s.title"></h1>

    <span class="badge hidden bg-warn-soft text-warn sm:inline-flex" x-show="s.demo" x-text="s.t('app.demo_badge')"></span>
    <span class="badge hidden bg-success-soft text-success sm:inline-flex" x-show="!s.demo" x-text="s.t('app.live_badge')"></span>

    {{-- language --}}
    <div class="flex rounded-lg border border-line p-0.5" role="group" x-bind:aria-label="s.t('settings.language')">
        <template x-for="l in s.locales" x-bind:key="l">
            <button type="button" class="rounded-md px-2 py-1 text-xs font-medium uppercase transition"
                    x-bind:class="s.locale === l ? 'bg-surface-3 text-fg' : 'text-muted hover:text-fg'"
                    x-bind:aria-pressed="s.locale === l" @click="s.setLocale(l)" x-text="l"></button>
        </template>
    </div>

    {{-- theme --}}
    <button type="button" class="btn-icon" @click="s.toggleTheme()" x-bind:aria-label="s.theme === 'dark' ? s.t('settings.theme_light') : s.t('settings.theme_dark')" x-bind:title="s.theme === 'dark' ? s.t('settings.theme_light') : s.t('settings.theme_dark')">
        <span class="size-[18px]" x-html="s.icon(s.theme === 'dark' ? 'sun' : 'moon')"></span>
    </button>

    {{-- keyboard shortcuts --}}
    <button type="button" class="btn-icon" @click="s.shortcutsOpen = true" x-bind:aria-label="s.t('shortcuts.title')" x-bind:title="s.t('shortcuts.title')" data-shortcut="?">
        <x-ui.icon name="help" class="size-[18px]" />
    </button>
</header>

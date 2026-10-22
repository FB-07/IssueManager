// Boot data, UI state, translations, theme, layout, keyboard shortcuts.
import { api } from '../api';
import { renderText, formatSize } from '../render';
import { MAX_FILES, MAX_BYTES, nextUid } from './shared';

const REFRESH_MS = 60000; // how often the open list of issues is refreshed in the background

export function coreSlice(boot) {
    return {
        user: boot.user,
        theme: boot.theme,
        locale: boot.locale,
        locales: boot.locales,
        i18n: boot.i18n,
        icons: boot.icons,
        trackerUrl: boot.trackerUrl,
        sidebarCollapsed: false,
        sidebarOpen: false,
        settingsOpen: false,
        shortcutsOpen: false,
        fileLimits: { max: MAX_FILES, mb: MAX_BYTES / 1048576 }, // the chat is scrolled up: the arrow that takes it back to the end shows
        altLabel: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌥' : 'Alt', // how the Alt key is written in shortcut hints
        profileMenu: false,
        page: boot.page ?? 'chat',
        toast: null,
        dragging: false,

        // ================================================================
        init() {
            try {
                this.sidebarCollapsed = localStorage.getItem('im.sidebar') === '1';
            } catch (e) {}
            document.documentElement.lang = this.locale;
            this.loadRecents();
            window.addEventListener('keydown', (e) => e.key === 'Escape' && this.selectMode && !this.confirmBulk && this.cancelSelect());
            window.addEventListener('keydown', (e) => this.onShortcut(e));
            window.addEventListener('popstate', () => this.routeFromLocation());
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.flushBatches();
                else this.autoRefresh();
            });
            setInterval(() => this.autoRefresh(), REFRESH_MS);
            window.addEventListener('pagehide', () => this.flushBatches());
            window.addEventListener('dragover', (e) => {
                if (e.dataTransfer?.types?.includes('Files')) {
                    e.preventDefault();
                    this.dragging = true;
                }
            });
            window.addEventListener('dragleave', (e) => {
                if (!e.relatedTarget) this.dragging = false;
            });
            window.addEventListener('drop', (e) => {
                e.preventDefault();
                this.dragging = false;
                if (e.dataTransfer?.files?.length) this.addFiles(e.dataTransfer.files);
            });
            const watchSelection = () => setTimeout(() => this.readSelection(), 0);
            document.addEventListener('mouseup', watchSelection);
            document.addEventListener('keyup', watchSelection);
            document.addEventListener('touchend', watchSelection);
            document.addEventListener('scroll', () => (this.selection = null), true);
            // the first page of issues is fetched in the background, so "Existing issues" opens with the list already there
            if (boot.configured && !boot.page?.startsWith?.('all')) setTimeout(() => !this.issuesOpen && !this.issuesLoading && !this.issueResults.length && this.searchIssues(1, true), 1500);
            if (boot.page === 'mine') this.openMine({ push: false });
            else if (boot.page === 'all') this.openIssuesPage({ push: false });
            else if (boot.page === 'analytics') this.openAnalytics({ push: false });
            else if (boot.initialId) this.openConversation(boot.initialId, { push: false });
        },
        t(key, params = {}) {
            const dict = this.i18n[this.locale] ?? {};
            let v = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), dict);
            if (v === undefined) v = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), this.i18n.en ?? {});
            if (typeof v !== 'string') return key;

            return v.replace(/:(\w+)/g, (m, name) => (params[name] !== undefined ? params[name] : m));
        },
        /** Display label of a catalogue value (type, priority, custom field…); the stored value never changes. */
        lbl(group, value) {
            if (value === null || value === undefined || value === '') return '';

            return this.i18n[this.locale]?.catalog?.[group]?.[value] ?? this.i18n.en?.catalog?.[group]?.[value] ?? value;
        },
        async setLocale(l) {
            if (l === this.locale) return;
            this.locale = l;
            document.documentElement.lang = l;
            this.safe(() => api('PATCH', '/api/preferences', { locale: l }));
        },
        icon(name) {
            return `<svg class="size-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${this.icons[name] ?? ''}</svg>`;
        },
        /** Markdown for chat text and previews; issue keys of the user's projects become links to YouTrack. */
        render(src) {
            return renderText(src, { issueUrl: this.trackerUrl, keys: (this.projects ?? this.catalog?.projects ?? []).map((p) => p.key) });
        },
        size: formatSize,
        setTheme(theme) {
            this.theme = theme;
            document.documentElement.dataset.theme = theme;
            this.safe(() => api('PATCH', '/api/preferences', { theme }));
        },
        toggleTheme() {
            this.setTheme(this.theme === 'dark' ? 'light' : 'dark');
        },
        /** Keyboard shortcuts (listed in the "?" dialog). Alt combos use the physical key, so they work with the Mac's Option too. */
        onShortcut(e) {
            if (e.isComposing || e.defaultPrevented) return;
            const el = e.target;
            const typing = el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
            const mod = e.metaKey || e.ctrlKey;
            const run = (fn) => {
                e.preventDefault();
                fn();
            };

            if (mod && !e.altKey && !e.shiftKey && e.code === 'KeyK') {
                return run(() => {
                    if (window.matchMedia('(min-width: 1024px)').matches) this.sidebarCollapsed = false;
                    else this.sidebarOpen = true;
                    this.startHistorySearch();
                });
            }
            if (e.altKey && !mod && !e.shiftKey) {
                const actions = {
                    KeyN: () => this.newChat(),
                    KeyI: () => this.openIssuesPage(),
                    KeyA: () => this.openAnalytics(),
                    KeyM: () => this.openMine(),
                    KeyD: () => (this.draftEnabled = !this.draftEnabled),
                    KeyB: () => this.toggleSidebar(),
                };
                if (actions[e.code]) return run(actions[e.code]);
            }
            if (!typing && !mod && !e.altKey) {
                if (e.key === '/') return run(() => this.focusComposer());
                if (e.key === '?') return run(() => (this.shortcutsOpen = true));
            }
        },
        toggleSidebar() {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                try {
                    localStorage.setItem('im.sidebar', this.sidebarCollapsed ? '1' : '0');
                } catch (e) {}
            } else {
                this.sidebarOpen = !this.sidebarOpen;
            }
        },
        notify(message, type = 'info') {
            this.toast = { message, type, id: nextUid() };
            const id = this.toast.id;
            setTimeout(() => this.toast?.id === id && (this.toast = null), 4500);
        },
        async safe(fn) {
            try {
                return await fn();
            } catch (e) {
                this.notify(e.message || this.t('errors.generic'), 'error');
            }
        },
        get title() {
            if (this.page === 'mine') return this.t('mine.title');
            if (this.page === 'all') return this.t('issues.title');
            if (this.page === 'analytics') return this.t('analytics.title');

            return this.activeConversation?.title || this.t('app.name');
        },
        /** "12 Oct 2026" in the language of the app. */
        shortDate(iso) {
            const d = new Date(iso);

            return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(this.locale === 'pt' ? 'pt-PT' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        },    };
}

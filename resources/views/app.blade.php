<!DOCTYPE html>
<html lang="{{ $boot['locale'] }}" data-theme="{{ $boot['theme'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>IssueManager</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <script>window.__BOOT__ = @json($boot);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="overflow-hidden">
<div x-data="{ s: $store.app }" x-cloak class="relative flex h-dvh w-full" @keydown.escape.window="s.sidebarOpen = false">

    <x-layout.sidebar />

    <div class="flex min-w-0 flex-1 flex-col">
        <x-layout.header />

        <main id="chat-scroll" class="relative flex-1 overflow-y-auto" @scroll.passive="s.onChatScroll($el)" x-effect="s.messages.length; s.processing; $nextTick(() => s.onChatScroll($el))">
            <x-chat.empty-state />
            <x-chat.messages />
            <x-mine.page />
            <x-issues.page />

            {{-- back to the end of the chat (sticks to the bottom of the scrolled area, takes no space) --}}
            <div class="pointer-events-none sticky bottom-0 z-20 h-0" x-show="s.page === 'chat'">
                <button type="button" x-show="s.showJump" x-cloak x-transition.opacity @click="s.scrollBottom()"
                        class="pointer-events-auto absolute bottom-3 left-1/2 flex size-9 -translate-x-1/2 items-center justify-center rounded-full border border-line bg-surface text-fg shadow-[var(--shadow)] transition hover:bg-surface-2"
                        x-bind:aria-label="s.t('chat.jump_bottom')" x-bind:title="s.t('chat.jump_bottom')">
                    <x-ui.icon name="arrow-down" class="size-[18px]" />
                </button>
            </div>
        </main>

        <x-chat.composer />
    </div>

    <x-layout.modals />

    {{-- drag & drop overlay --}}
    <div x-show="s.dragging" x-transition.opacity class="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center bg-bg/80 backdrop-blur-sm">
        <div class="rounded-2xl border-2 border-dashed border-accent px-10 py-8 text-center">
            <x-ui.icon name="paperclip" class="mx-auto mb-2 size-6 text-accent" />
            <p class="text-sm font-medium" x-text="s.t('composer.drop')"></p>
        </div>
    </div>

    {{-- toast --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-24 z-[70] flex justify-center px-4" aria-live="polite">
        <div x-show="s.toast" x-transition x-cloak
             class="pointer-events-auto flex max-w-md items-start gap-2 rounded-xl border px-4 py-3 text-sm shadow-[var(--shadow)]"
             x-bind:class="s.toast?.type === 'error' ? 'border-danger/40 bg-surface text-fg' : s.toast?.type === 'success' ? 'border-success/40 bg-surface text-fg' : 'border-line bg-surface text-fg'">
            <span class="mt-0.5 size-4 shrink-0" x-bind:class="s.toast?.type === 'error' ? 'text-danger' : s.toast?.type === 'success' ? 'text-success' : 'text-accent'"
                  x-html="s.icon(s.toast?.type === 'error' ? 'alert' : 'check-circle')"></span>
            <span x-text="s.toast?.message"></span>
        </div>
    </div>
</div>
</body>
</html>

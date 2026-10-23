<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('ui.login.title') }} · IssueManager</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/login.js'])
</head>
<body class="flex min-h-dvh items-center justify-center px-4">
<main class="w-full max-w-sm">
    <div class="mb-8 flex flex-col items-center text-center">
        <img src="{{ asset('images/proside-logo.png') }}" alt="Proside" width="560" height="158" class="logo-adaptive mb-6 h-10 w-auto select-none" draggable="false">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('ui.login.title') }}</h1>
        <p class="mt-2 text-sm leading-relaxed text-muted">{{ __('ui.login.subtitle') }}</p>
    </div>

    @if (session('error'))
        <p class="mb-4 flex items-start gap-2 rounded-xl border border-danger/40 bg-danger-soft px-3.5 py-3 text-sm text-danger" role="alert">
            <x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0" /><span>{{ session('error') }}</span>
        </p>
    @endif

    @if ($oauthConfigured)
        {{-- accounts used before in this browser (name and email only, kept in localStorage): filled by the script below --}}
        <section id="previous" hidden class="mb-5" aria-labelledby="previous-title">
            <h2 id="previous-title" class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-faint">{{ __('ui.login.previous') }}</h2>
            <ul id="previous-list" class="space-y-2"></ul>
        </section>

        <a id="main-login" href="{{ route('auth.youtrack') }}" class="btn btn-primary w-full py-2.5">{{ __('ui.login.button') }}</a>
        <a id="other-login" hidden href="{{ route('auth.youtrack', ['other' => 1]) }}" class="btn w-full py-2.5">{{ __('ui.login.other_account') }}</a>
        <p class="mt-3 text-center text-xs text-faint">{{ __('ui.login.domain_note', ['domain' => implode(', @', config('issuemanager.allowed_domains'))]) }}</p>
    @else
        <p class="rounded-xl border border-line bg-surface px-3.5 py-3 text-sm text-muted">{{ __('ui.login.not_configured') }}</p>
    @endif

    @if ($devLogin)
        <form method="POST" action="{{ route('auth.dev') }}" class="mt-6 border-t border-line pt-5">
            @csrf
            <button class="btn w-full" type="submit">{{ __('ui.login.dev') }}</button>
        </form>
    @endif
</main>

@if ($oauthConfigured)
@php
    $texts = ['continueAs' => __('ui.login.continue_as', ['name' => ':name']), 'remove' => __('ui.login.remove_account', ['name' => ':name'])];
@endphp
<script>
(() => {
    const KEY = 'im.accounts';
    const T = @json($texts);
    const base = @json(route('auth.youtrack'));
    // only the last account is offered (the app stores just that one; older lists are cut to their first entry)
    const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]').filter((a) => a && typeof a.email === 'string').slice(0, 1); } catch (e) { return []; } };
    const write = (list) => { try { list.length ? localStorage.setItem(KEY, JSON.stringify(list)) : localStorage.removeItem(KEY); } catch (e) {} };
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
    const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

    const render = () => {
        const list = read();
        const box = document.getElementById('previous');
        const ul = document.getElementById('previous-list');
        ul.replaceChildren();
        box.hidden = list.length === 0;
        document.getElementById('other-login').hidden = list.length === 0; // with no history the main button already is "another account"
        document.getElementById('main-login').hidden = list.length > 0;

        list.forEach((a) => {
            const li = el('li', 'relative');
            // choosing a remembered account goes through the Hub; the app then checks it really is that account
            const go = el('a', 'group flex min-w-0 items-center gap-3 rounded-xl border border-line bg-surface py-2.5 pl-3 pr-12 transition hover:border-line-strong hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent');
            go.href = base + '?account=' + encodeURIComponent(a.email);
            go.title = T.continueAs.replace(':name', a.name || a.email);
            go.append(el('span', 'flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent', initials(a.name || a.email)));
            const txt = el('span', 'min-w-0 flex-1');
            txt.append(el('span', 'block truncate text-sm font-medium', a.name || a.email), el('span', 'block truncate text-xs text-muted', a.email));
            go.append(txt);
            const rm = el('button', 'btn-icon absolute right-2 top-1/2 size-8 -translate-y-1/2 hover:text-danger');
            rm.type = 'button';
            rm.title = rm.ariaLabel = T.remove.replace(':name', a.name || a.email);
            rm.innerHTML = '<svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
            rm.addEventListener('click', () => { write(read().filter((x) => x.email.toLowerCase() !== a.email.toLowerCase())); render(); });
            li.append(go, rm);
            ul.append(li);
        });
    };

    write(read()); // prune a longer list stored earlier
    render();
})();
</script>
@endif
</body>
</html>

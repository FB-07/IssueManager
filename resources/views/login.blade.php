<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('ui.login.title') }} · IssueManager</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    @vite(['resources/css/app.css'])
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
        <a href="{{ route('auth.youtrack') }}" class="btn btn-primary w-full py-2.5">{{ __('ui.login.button') }}</a>
        <p class="mt-3 text-center text-xs text-faint">{{ __('ui.login.invite_only') }}</p>
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
</body>
</html>

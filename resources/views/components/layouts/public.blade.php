@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>{{ $title ? $title.' – ' : '' }}{{ config('app.name') }}</title>
    <meta name="locales" content="{{ implode(',', config('app.available_locales')) }}">
    @vite('resources/css/app.css')
    @stack('head')
</head>
<body class="bg-slate-50 text-brand-950 antialiased">
<div class="flex min-h-screen flex-col">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between p-4">
            <a href="{{ route('home') }}" aria-label="{{ config('app.name') }}">
                <img src="{{ asset('storage/images/rentacar-logo.png') }}" alt="{{ config('app.name') }}" class="h-12 w-auto">
            </a>
            <nav class="flex items-center gap-3 text-sm font-medium text-slate-600 sm:gap-6">
                <a href="{{ route('vehicles.index') }}" class="hidden hover:text-brand-700 md:inline">{{ __('Cars') }}</a>
                <a href="{{ route('locations') }}" class="hidden hover:text-brand-700 md:inline">{{ __('Locations') }}</a>
                <a href="#" class="hidden hover:text-brand-700 md:inline">{{ __('Contact') }}</a>

                <div class="inline-flex overflow-hidden rounded-lg text-xs font-semibold ring-1 ring-slate-300" role="group" aria-label="{{ __('Language') }}">
                    @foreach (config('app.available_locales') as $code)
                        <a href="{{ route('locale', $code) }}" @if (app()->getLocale() === $code) aria-current="true" @endif
                           class="px-2.5 py-1 {{ app()->getLocale() === $code ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ strtoupper($code) }}</a>
                    @endforeach
                </div>

                <a href="{{ route('admin') }}" class="rounded-lg bg-brand-900 px-3 py-1.5 text-white hover:bg-brand-700">{{ __('Admin') }}</a>
            </nav>
        </div>
    </header>

    <main class="flex-1">{{ $slot }}</main>

    <footer class="border-t border-slate-200 bg-white py-6 text-center text-sm text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name') }}
    </footer>
</div>
@if (config('visitors.enabled'))
{{-- Counts the visit once the page has been open for a moment. Robots that only download the HTML never get here. --}}
<script>
setTimeout(function () {
    try {
        if (navigator.webdriver || document.visibilityState === 'hidden') return;
        fetch('/t', {
            method: 'POST', keepalive: true, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ p: location.pathname, r: document.referrer, c: new URLSearchParams(location.search).get('visits') }),
        });
    } catch (e) {}
}, 1500);
</script>
@endif
</body>
</html>

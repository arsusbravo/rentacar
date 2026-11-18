<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>{{ __('Admin login') }} – {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 text-brand-950">
    <form method="POST" action="{{ route('login.store') }}" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        @csrf
        <img src="{{ asset('storage/images/rentacar-logo.png') }}" alt="{{ config('app.name') }}" class="mx-auto h-16 w-auto">
        <h1 class="text-center text-lg font-semibold">{{ __('Admin login') }}</h1>
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="mb-1 block text-sm font-medium">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
        </div>
        <button class="w-full rounded-lg bg-brand-700 px-4 py-2 font-medium text-white hover:bg-brand-800">{{ __('Sign in') }}</button>
    </form>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>{{ __('Admin') }} – {{ config('app.name') }}</title>
    <meta name="locales" content="{{ implode(',', config('app.available_locales')) }}">
    @vite(['resources/css/app.css', 'resources/js/admin.js'])
</head>
<body class="bg-slate-50 text-brand-950 antialiased">
    <div id="app"></div>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>{{ __('Admin login') }} – {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-8 text-brand-950">
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

    {{-- Demo: the accounts the seeder creates --}}
    <aside class="mt-4 w-full max-w-sm rounded-xl bg-brand-50 p-5 text-sm ring-1 ring-brand-100" aria-labelledby="demo-title">
        <h2 id="demo-title" class="font-semibold text-brand-900">{{ __('Demo accounts') }}</h2>
        <p class="mt-1 text-brand-800">{{ __('This is a demo. Try the admin panel with one of these accounts.') }}</p>
        <ul class="mt-3 space-y-2">
            @foreach ([['label' => __('Administrator'), 'hint' => __('Full access, including user management'), ...Database\Seeders\DemoUsersSeeder::DEMO[0]], ['label' => __('Employee'), 'hint' => __('No access to user management'), ...Database\Seeders\DemoUsersSeeder::DEMO[1]]] as $demo)
                <li class="rounded-lg bg-white p-3 ring-1 ring-brand-100">
                    <div class="flex items-center justify-between gap-2">
                        <strong class="text-brand-900">{{ $demo['label'] }}</strong>
                        <button type="button" data-demo-email="{{ $demo['email'] }}" data-demo-password="{{ $demo['password'] }}"
                                class="rounded-md px-2 py-1 text-xs font-medium text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">{{ __('Fill in') }}</button>
                    </div>
                    <p class="text-xs text-slate-500">{{ $demo['hint'] }}</p>
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                        <dt class="text-slate-500">{{ __('Email') }}</dt><dd class="font-mono select-all">{{ $demo['email'] }}</dd>
                        <dt class="text-slate-500">{{ __('Password') }}</dt><dd class="font-mono select-all">{{ $demo['password'] }}</dd>
                    </dl>
                </li>
            @endforeach
        </ul>
    </aside>
    <script>
        document.querySelectorAll('[data-demo-email]').forEach((b) => b.addEventListener('click', () => {
            document.getElementById('email').value = b.dataset.demoEmail;
            document.getElementById('password').value = b.dataset.demoPassword;
            document.querySelector('form button').focus();
        }));
    </script>
</body>
</html>

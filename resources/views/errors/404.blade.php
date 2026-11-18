<x-layouts.public :title="__('Page not found')">
    <div class="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 py-24 text-center">
        <h1 class="text-3xl font-semibold">{{ __('Page not found') }}</h1>
        <a href="{{ route('home') }}" class="text-brand-700 hover:underline">{{ __('Back to home') }}</a>
    </div>
</x-layouts.public>

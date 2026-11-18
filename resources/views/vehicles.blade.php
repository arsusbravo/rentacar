<x-layouts.public :title="__('Choose your vehicle')">
    @push('head')
        @vite('resources/js/vehicles.js')
    @endpush

    <div class="mx-auto max-w-6xl px-4 py-10">
        <h1 class="text-3xl font-bold tracking-tight">{{ __('Choose your vehicle') }}</h1>

        {{-- Vue island (vehicles.js): the same search form as on the homepage, prefilled. Searching again reloads this page. --}}
        <div id="vehicles-search" class="mt-6"
             data-branches="{{ $branches->toJson() }}"
             data-categories="{{ $categoryOptions->toJson() }}"
             data-initial="{{ json_encode($initial) }}"
             data-vehicles-url="{{ route('vehicles.index') }}"></div>

        @forelse ($groups as $category)
            <section class="mt-12">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-2xl font-semibold">{{ $category->name }}</h2>
                    @if ($selected)
                        <a href="{{ route('vehicles.index', $rentQuery) }}" class="text-sm text-brand-700 hover:underline">{{ __('All vehicle types') }}</a>
                    @endif
                </div>

                {{-- items-start: opening one card's specifications does not stretch its neighbours --}}
                <div class="mt-5 grid items-start gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($category->classes as $class)
                        <x-class-card :vehicle-class="$class" :rent-query="$rentQuery" />
                    @endforeach
                </div>
            </section>
        @empty
            <p class="mt-12 text-slate-500">{{ __('No vehicles available yet.') }}</p>
        @endforelse
    </div>
</x-layouts.public>

<x-layouts.public :title="__('Locations')">
    <div class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">{{ __('Our branches') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('Pick up at the opening hours below. Returning is possible at any branch, 24 hours a day.') }}</p>

        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($branches as $branch)
                <article class="flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <img src="{{ $branch->image_url ?? asset('images/branch-placeholder.svg') }}" alt="{{ $branch->image_path ? __('Photo of :name', ['name' => $branch->name]) : '' }}" loading="lazy" class="aspect-3/2 w-full object-cover">
                    <div class="flex flex-1 flex-col p-5">
                    <h2 class="text-lg font-semibold">{{ $branch->city }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ $branch->name }}<br>
                        {{ $branch->address }}@if ($branch->postal_code), {{ $branch->postal_code }}@endif
                    </p>
                    @if ($branch->phone || $branch->email)
                        <p class="mt-2 text-sm text-slate-600">{{ $branch->phone }}@if ($branch->phone && $branch->email) · @endif{{ $branch->email }}</p>
                    @endif

                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Opening hours') }}</h3>
                    <dl class="mt-2 space-y-1 text-sm">
                        @foreach ($branch->openingHours as $hours)
                            <div class="flex justify-between gap-4">
                                <dt class="capitalize">{{ now()->startOfWeek()->addDays($hours->weekday - 1)->locale(app()->getLocale())->isoFormat('dddd') }}</dt>
                                <dd class="{{ $hours->is_closed ? 'text-slate-400' : '' }}">{{ $hours->is_closed ? __('Closed') : $hours->opens_at.' – '.$hours->closes_at }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <a href="{{ route('rent', ['pickup' => $branch->id]) }}" class="mt-5 inline-block self-start rounded-lg bg-accent-500 px-4 py-2 text-sm font-semibold text-brand-950 hover:bg-accent-400">{{ __('Rent from this branch') }}</a>
                    </div>
                </article>
            @empty
                <p class="text-slate-500">{{ __('No branches available yet.') }}</p>
            @endforelse
        </div>
    </div>
</x-layouts.public>

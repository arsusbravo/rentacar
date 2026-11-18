<x-layouts.public>
    @push('head')
        @vite('resources/js/home.js')
    @endpush

    <section class="relative isolate overflow-hidden bg-brand-950 text-white">
        <img src="{{ asset('storage/images/rentacar-header.png') }}" alt="" class="absolute inset-0 -z-20 h-full w-full object-cover">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-brand-950/80 via-brand-950/15 to-transparent max-lg:bg-brand-950/60"></div>

        <div class="mx-auto grid max-w-6xl items-center gap-8 px-4 py-12 lg:min-h-136 lg:grid-cols-[1fr_21rem] lg:py-16">
            <div class="max-lg:text-center lg:max-w-md">
                <h1 class="text-4xl font-bold tracking-tight drop-shadow sm:text-5xl">{{ __('Find your perfect ride') }}</h1>
                <p class="mt-4 text-lg text-white/90 drop-shadow">{{ __('Affordable car rental, simple booking.') }}</p>
                <ul class="mt-6 space-y-2 text-sm text-white/90 max-lg:inline-block max-lg:text-left">
                    @if ($branches->isNotEmpty())
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-accent-400" aria-hidden="true">✓</span>{{ __('Pick up in :cities', ['cities' => \Illuminate\Support\Arr::join($branches->pluck('city')->unique()->all(), ', ', __(' and '))]) }}</li>
                    @endif
                    <li class="flex items-start gap-2"><span class="mt-0.5 text-accent-400" aria-hidden="true">✓</span>{{ __('Return to any branch, 24 hours a day') }}</li>
                    <li class="flex items-start gap-2"><span class="mt-0.5 text-accent-400" aria-hidden="true">✓</span>{{ __('Choose your own pick-up and return time') }}</li>
                </ul>
            </div>

            {{-- Vue island (home.js): same rules as the checkout. Without JavaScript the plain form below sends the branches to the checkout. --}}
            <div id="home-search" class="min-h-80 lg:col-start-2"
                 data-branches="{{ $branches->toJson() }}"
                 data-categories="{{ $categoryOptions->toJson() }}"
                 data-vehicles-url="{{ route('vehicles.index') }}"></div>
            <noscript>
                <form method="GET" action="{{ route('vehicles.index') }}" class="w-full space-y-3 rounded-xl bg-white/95 p-4 text-brand-950 shadow-xl backdrop-blur">
                <label class="block text-xs font-medium text-slate-500">{{ __('Vehicle type') }}
                    <select name="category" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-brand-950">
                        <option value="">{{ __('All vehicle types') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-medium text-slate-500">{{ __('Pick-up location') }}
                    <select name="pickup" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-brand-950">
                        <option value="">{{ __('Choose a branch') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->city }} – {{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-medium text-slate-500">{{ __('Return location') }}
                    <select name="return" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-brand-950">
                        <option value="">{{ __('Same as pick-up location') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->city }} – {{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="w-full rounded-lg bg-accent-500 px-4 py-2 text-sm font-semibold text-brand-950 hover:bg-accent-400">{{ __('Plan your rental') }}</button>
                <p class="text-[11px] leading-snug text-slate-500">{{ __('You choose the dates and times on the next page.') }}</p>
            </form>
            </noscript>
        </div>
    </section>

    @if ($categories->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-16">
            <h2 class="text-2xl font-semibold">{{ __('Our vehicles') }}</h2>
            <p class="mt-1 text-slate-600">{{ __('Whatever you need to get going: choose the type of vehicle that suits you.') }}</p>

            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($categories as $category)
                    <a href="{{ route('vehicles.index', ['category' => $category->slug]) }}"
                       class="group flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:shadow-md hover:ring-brand-300 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-hidden">
                        <div class="relative">
                            <img src="{{ $category->imageUrl() }}" alt="" loading="lazy" class="aspect-3/2 w-full object-cover">
                            @if (($from = $category->fromDayPriceCents()) !== null)
                                <span class="absolute left-0 top-3 flex items-baseline gap-1 rounded-r-full bg-brand-800 py-1 pl-3 pr-3.5 text-white shadow-md">
                                    <span class="text-[11px] font-medium uppercase tracking-wide text-brand-100">{{ __('From') }}</span>
                                    <span class="text-sm font-bold tabular-nums">€&nbsp;{{ number_format($from / 100, $from % 100 === 0 ? 0 : 2, app()->getLocale() === 'nl' ? ',' : '.', app()->getLocale() === 'nl' ? '.' : ',') }}</span>
                                    <span class="text-[11px] text-brand-100">/ {{ __('day') }}</span>
                                </span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2 p-5">
                            <h3 class="text-lg font-semibold">{{ $category->name }}</h3>
                            <span class="text-brand-700 transition group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- How the rental application works --}}
    @php
        $nl = app()->getLocale() === 'nl';
        $money = fn (int $cents) => '€ '.number_format($cents / 100, 2, $nl ? ',' : '.', $nl ? '.' : ',');
        $unit = fn (array $b) => trans_choice(':count '.str_replace('_', ' ', $b['unit']).'|:count '.str_replace('_', ' ', $b['unit']).'s', $b['count']);
        $pricing = config('pricing');
    @endphp
    <section id="how-it-works" class="mt-16 border-t border-slate-200 bg-white" aria-labelledby="how-title">
        <div class="mx-auto max-w-6xl px-4 py-16">
            {{-- This is a demo --}}
            <div class="rounded-xl bg-amber-50 p-5 text-amber-900 ring-1 ring-amber-200" role="note">
                <h2 class="text-lg font-semibold">{{ __('This is a demo site') }}</h2>
                <p class="mt-1 text-sm">{{ __('This site shows how the rental system works. The vehicles, prices, branches, extra options and deposits are examples. Nothing is really booked, paid or charged, and the details you enter are not stored.') }}</p>
            </div>

            <div class="mt-12 max-w-2xl">
                <h2 id="how-title" class="text-2xl font-semibold">{{ __('How it works') }}</h2>
                <p class="mt-1 text-slate-600">{{ __('From searching to booking in four steps.') }}</p>
            </div>

            <ol class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Search', 'Choose the type of vehicle, where and when you pick it up, and where and when you bring it back.'],
                    ['Choose your vehicle', 'Compare the vehicles of your type with their price from, specifications and example models. Press Select on the one you like.'],
                    ['Make it yours', 'Check the price, choose fuel and transmission and add extras such as winter tyres, navigation or a tow bar. Expect to drive more than the included kilometres (for example 250 km)? Add extra kilometres. You can also lower the own risk. The total and the deposit update as you go.'],
                    ['Fill in your details and book', 'Enter your details, confirm that you hold a valid driving licence, accept the terms and press Book. On this demo site nothing is really booked or paid.'],
                ] as $i => [$title, $text])
                    <li class="relative rounded-xl bg-slate-50 p-5 ring-1 ring-slate-200">
                        <span class="flex size-9 items-center justify-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                        <h3 class="mt-4 font-semibold">{{ __($title) }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ __($text) }}</p>
                    </li>
                @endforeach
            </ol>

            <p class="mt-6 rounded-lg bg-brand-50 p-4 text-sm text-slate-700 ring-1 ring-brand-100">
                <strong class="text-brand-800">{{ __('Pick-up and return') }}:</strong>
                {{ __('You pick up during the opening hours of the branch (not on weekends). You can return to any branch, 24 hours a day.') }}
            </p>

            {{-- How the price is calculated --}}
            <div class="mt-16 grid gap-10 lg:grid-cols-[1fr_22rem]" aria-labelledby="calc-title" role="group">
                <div>
                    <h2 id="calc-title" class="text-2xl font-semibold">{{ __('How your price is calculated') }}</h2>
                    <p class="mt-1 text-slate-600">{{ __('Everything you see in the booking overview is calculated like this.') }}</p>

                    <dl class="mt-6 space-y-5 text-sm">
                        <div>
                            <dt class="font-semibold">{{ __('Rental price') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('Every class has prices for a half day (up to :hours hours), a day, a week, a month and a year. For your rental period we automatically calculate the cheapest combination. Every started hour counts.', ['hours' => $pricing['half_day_hours']]) }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('Fuel and transmission') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('The price depends on your choice. Petrol and manual are preselected; diesel or an automatic can have a different price.') }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('Lowering the own risk') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('Optional. You pay the price per day times the number of days; every started 24 hours is a day. In return your own risk drops to the lower amount.') }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('Extra options') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('Per day (price times the number of days), per unit (price times the quantity you choose) or per time (charged once).') }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('VAT') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('All prices exclude VAT. :rate% VAT is added to the total. You see the total both excluding and including VAT.', ['rate' => $pricing['vat_rate']]) }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('Deposit') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('The deposit is at least the base amount of the class and at least the price including VAT + € :margin, rounded up to € :step. If you keep the full own risk, € :surcharge is added. The deposit is not part of the total and is refunded after you return the vehicle. A rental of :days days or more is leasing and needs no deposit.', ['margin' => $pricing['deposit']['margin'], 'step' => $pricing['deposit']['round_to'], 'surcharge' => $pricing['deposit']['own_risk_surcharge'], 'days' => $pricing['deposit']['leasing_from_days']]) }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('Kilometres') }}</dt>
                            <dd class="mt-1 text-slate-600">{{ __('A number of kilometres is included in the rental price, per rental (for example 250 km). Do you expect to drive more? Add extra kilometres in the booking overview at the price per km of the class. Kilometres driven beyond that are settled after the return at the same price (excluding VAT).') }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- Worked example, produced by the real calculators --}}
                <aside class="self-start rounded-xl bg-slate-50 p-5 ring-1 ring-slate-200" aria-labelledby="example-title">
                    <h3 id="example-title" class="font-semibold">{{ __('Example') }}</h3>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Day price :day, week price :week, :days days, with winter tyres (:extra per day). Amounts excluding VAT unless stated.', ['day' => $money(\App\Pricing\ExampleQuote::DAY_PRICE * 100), 'week' => $money(\App\Pricing\ExampleQuote::WEEK_PRICE * 100), 'days' => $example['days'], 'extra' => $money(\App\Pricing\ExampleQuote::EXTRA_PER_DAY * 100)]) }}</p>

                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt>{{ __('Rental price') }}<span class="block text-xs text-slate-500">{{ collect($example['breakdown'])->map($unit)->join(' + ') }}</span></dt>
                            <dd class="whitespace-nowrap font-medium">{{ $money($example['rental_cents']) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>{{ __('Winter tyres') }}<span class="block text-xs text-slate-500">{{ $example['days'] }} × {{ $money(\App\Pricing\ExampleQuote::EXTRA_PER_DAY * 100) }}</span></dt>
                            <dd class="whitespace-nowrap font-medium">{{ $money($example['extra_cents']) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 border-t border-slate-200 pt-2"><dt>{{ __('Total excl. VAT') }}</dt><dd class="font-medium">{{ $money($example['subtotal_cents']) }}</dd></div>
                        <div class="flex justify-between gap-3 text-xs text-slate-500"><dt>{{ __('VAT :rate%', ['rate' => $pricing['vat_rate']]) }}</dt><dd>{{ $money($example['vat_cents']) }}</dd></div>
                        <div class="flex items-baseline justify-between gap-3"><dt class="font-semibold">{{ __('Total incl. VAT') }}</dt><dd class="text-lg font-bold text-brand-800">{{ $money($example['total_cents']) }}</dd></div>
                    </dl>

                    <div class="mt-4 border-t border-dashed border-slate-300 pt-4 text-sm">
                        <p class="font-semibold">{{ __('Deposit') }} <span class="font-normal text-slate-500">({{ __('base amount :base', ['base' => $money(\App\Pricing\ExampleQuote::BASE_DEPOSIT * 100)]) }})</span></p>
                        <p class="mt-1 text-xs text-slate-600">{{ $money($example['total_cents']) }} + € {{ $pricing['deposit']['margin'] }} = {{ $money($example['price_plus_margin_cents']) }} → <strong>{{ $money($example['deposit_cents']) }}</strong></p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Keeping the full own risk: + € :surcharge', ['surcharge' => $pricing['deposit']['own_risk_surcharge']]) }} → <strong>{{ $money($example['deposit_with_own_risk_cents']) }}</strong></p>
                    </div>
                </aside>
            </div>

            <a href="{{ route('vehicles.index') }}" class="mt-10 inline-block rounded-lg bg-accent-500 px-6 py-2.5 text-sm font-semibold text-brand-950 hover:bg-accent-400 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-hidden">{{ __('Browse all vehicles') }}</a>
        </div>
    </section>
</x-layouts.public>

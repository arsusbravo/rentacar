@props(['vehicleClass', 'rentQuery' => []])
@php
    $class = $vehicleClass;
    $nl = app()->getLocale() === 'nl';
    $fmt = fn ($n) => number_format($n, 0, $nl ? ',' : '.', $nl ? '.' : ',');
    $money = fn (int $cents) => '€ '.number_format($cents / 100, 2, $nl ? ',' : '.', $nl ? '.' : ',');
    $from = $class->fromDayPriceCents();

    $luggage = collect([
        $class->baggage_large ? __(':count large', ['count' => $class->baggage_large]) : null,
        $class->baggage_small ? __(':count small', ['count' => $class->baggage_small]) : null,
    ])->filter()->join(' + ');

    // label, value, optional note, full width?
    $specs = array_filter([
        [__('Class'), $class->name, null, true],
        [__('Seats'), $class->seats, null, false],
        $class->doors ? [__('Doors'), $class->doors, null, false] : null,
        [__('Fuel'), implode(' / ', $class->fuelLabels()), $class->requiresFuelChoice() ? __('You choose when booking') : null, $class->requiresFuelChoice()],
        [__('Transmission'), implode(' / ', $class->transmissionLabels()), $class->requiresTransmissionChoice() ? __('You choose when booking') : null, $class->requiresTransmissionChoice()],
        $luggage !== '' ? [__('Luggage'), $luggage, null, true] : null,
        $class->cargo_volume_liters ? [__('Cargo volume'), $fmt($class->cargo_volume_liters).' L', null, false] : null,
        $class->payload_kg ? [__('Payload'), $fmt($class->payload_kg).' kg', null, false] : null,
        [__('Included km'), $class->km_included > 0 ? $fmt($class->km_included).' km' : __('None'), null, false],
        [__('Driving licence'), $class->license_category, null, false],
    ]);
@endphp

<article class="flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
    <img src="{{ $class->imageUrl() }}" alt="" loading="lazy" class="aspect-3/2 w-full object-cover">

    <div class="flex flex-1 flex-col p-5">
        <p class="font-semibold leading-snug text-brand-950">
            {{ $class->example_models }}
            <span class="whitespace-nowrap font-normal text-slate-400">{{ __('or similar') }}</span>
        </p>

        <p class="mt-2 text-sm">
            @if ($from !== null)
                <span class="text-slate-500">{{ __('From') }}</span>
                <span class="text-lg font-semibold text-brand-800">{{ $money($from) }}</span>
                <span class="text-slate-500">{{ __('per day') }} · {{ __('excl. VAT') }}</span>
            @else
                <span class="text-slate-400">{{ __('Price on request') }}</span>
            @endif
        </p>

        <details class="group mt-4">
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-800 transition hover:bg-brand-100 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-hidden [&::-webkit-details-marker]:hidden">
                {{ __('Specifications') }}
                <svg class="size-4 transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
            </summary>

            <dl class="mt-3 grid grid-flow-dense grid-cols-2 gap-2">
                @foreach ($specs as [$label, $value, $note, $wide])
                    <div class="{{ $wide ? 'col-span-2' : '' }} rounded-lg bg-sky-50 px-3 py-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-sky-600">{{ $label }}</dt>
                        <dd class="text-sm font-semibold text-sky-950">{{ $value }}</dd>
                        @if ($note)
                            <dd class="text-xs text-sky-600">{{ $note }}</dd>
                        @endif
                    </div>
                @endforeach
            </dl>
        </details>

        <a href="{{ route('rent', $rentQuery + ['class' => $class->id]) }}" data-select-class
           class="mt-5 block rounded-lg bg-accent-500 px-4 py-2 text-center text-sm font-semibold text-brand-950 hover:bg-accent-400 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-hidden">{{ __('Select') }}</a>
    </div>
</article>

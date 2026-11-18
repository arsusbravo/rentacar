<x-layouts.public :title="__('Rental details')">
    @push('head')
        @vite('resources/js/checkout.js')
    @endpush

    <div class="mx-auto max-w-6xl px-4 py-10">
        {{-- Vue checkout: client chooses all rental details. --}}
        <div id="checkout-app"
             data-branches="{{ $branches->toJson() }}"
             data-initial="{{ json_encode($initial) }}"
             data-extras="{{ json_encode($extras) }}"
             data-min-age="{{ config('booking.min_driver_age') }}"
             data-vehicles-url="{{ $vehiclesUrl }}"
             data-vehicle-class="{{ json_encode($vehicleClass) }}"></div>
    </div>
</x-layouts.public>

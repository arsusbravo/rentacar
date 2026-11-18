<?php

return [
    // VAT (BTW) added at checkout: prices in the admin exclude VAT.
    'vat_rate' => 21,

    // Tariff units. A rental is priced with the cheapest combination of these (see RentalPriceCalculator).
    'half_day_hours' => 6,   // a half-day price covers up to this many hours
    'month_days' => 30,
    'year_days' => 365,

    // Deposit (borg): max(base deposit of the class, price incl. VAT + margin), rounded UP, plus a surcharge
    // when the client keeps the full own risk (does not lower it).
    'deposit' => [
        'margin' => 150,            // EUR the deposit is at least above the price the client pays
        'round_to' => 50,           // EUR: rounded up to a multiple of this
        'own_risk_surcharge' => 250, // EUR added when the class has an own risk and the client does not lower it
        'leasing_from_days' => 30,  // a rental this long (or longer) is leasing: no deposit
    ],

    // Kilometres: a number is included per class (vehicle_classes.km_included); the client may book extra ones.
    'km' => [
        'max_extra' => 50000, // most extra kilometres that can be booked in advance
    ],

    // Longest rental that can be priced / requested.
    'max_rental_days' => 1825,
];

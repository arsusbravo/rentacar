<?php

/*
| Allowed values for vehicle class attributes. These are plain strings in the database
| (no ENUM), so adding a value only needs an entry here plus a label in
| lang/{nl,en}/vehicles.php: no migration.
*/
return [
    'fuel_types' => ['petrol', 'diesel', 'hybrid', 'plugin_hybrid', 'electric', 'lpg'],

    // Preselected on the checkout when a class offers it (otherwise the class's first fuel type).
    'default_fuel_type' => 'petrol',

    'transmissions' => ['manual', 'automatic'],

    // Preselected on the checkout (prices shown are for this one); the others are chosen as an alternative.
    'default_transmission' => 'manual',

    // Driving licence categories (Dutch rijbewijs)
    'license_categories' => ['B', 'BE', 'C1', 'C1E', 'C', 'CE', 'D1', 'D'],
];

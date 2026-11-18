<?php

/*
| How an extra is charged. Plain strings in the database (no ENUM): add a value here plus a label in
| lang/{nl,en}/extras.php and the checkout's price calculation.
|
|   per_day  : price x number of rental days
|   per_unit : price x quantity chosen by the client (up to the extra's max_quantity)
|   per_time : price once per rental
*/
return [
    'price_units' => ['per_day', 'per_unit', 'per_time'],
];

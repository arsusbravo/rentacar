<?php

return [
    // Count visits to the public pages (shown on the admin "Bezoekers" page).
    'enabled' => env('VISITORS_ENABLED', true),

    // Visits from these IP addresses are never counted (yours). Comma separated in .env: VISITOR_IGNORED_IPS=1.2.3.4,5.6.7.8
    // The IP address itself is never stored; it is only compared with this list.
    'ignored_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('VISITOR_IGNORED_IPS', ''))))),

    // Days a visit is kept.
    'retention_days' => 90,
];

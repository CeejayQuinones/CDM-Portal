<?php

$configured = array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')));

return [
    // Leave empty unless requests pass through known reverse-proxy addresses/CIDRs.
    'proxies' => array_values(array_filter($configured)),
];

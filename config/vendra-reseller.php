<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reseller Domain
    |--------------------------------------------------------------------------
    |
    | The host the reseller panel is served on. It defaults to the "reseller."
    | subdomain of the host in "APP_URL", so a deployment that sets APP_URL
    | needs nothing further. Set this to serve the panel somewhere else.
    |
    */

    'domain' => env('VENDRA_RESELLER_DOMAIN', 'reseller.'.(string) parse_url((string) env('APP_URL'), PHP_URL_HOST)),

];

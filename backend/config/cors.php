<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The RUM beacon (extensions/rum-snippet) runs on the merchant's
    | storefront domain and posts to this API's domain - a genuinely
    | cross-origin request. navigator.sendBeacon() always sends with the
    | browser's "include" credentials mode with no way to opt out from JS,
    | and the Fetch spec forbids pairing that with a literal '*' allow-origin
    | response - it must be supports_credentials => true (which makes the
    | CORS service echo back the exact requesting Origin instead of '*').
    | The embedded app's own /api/* calls are Bearer-token authenticated,
    | not cookie-based, so enabling this doesn't expose them to anything.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

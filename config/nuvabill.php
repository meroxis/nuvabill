<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | The version of this copy of Nuvabill. The release script checks that it
    | matches the Git tag, and the updater compares it with new releases.
    |
    */

    'version' => '0.4.5',

    /*
    |--------------------------------------------------------------------------
    | Installed
    |--------------------------------------------------------------------------
    |
    | Leave empty in production: the web installer writes a lock file when it
    | finishes. Set to true in development and tests to skip the installer.
    |
    */

    'installed' => env('NUVABILL_INSTALLED'),

    /*
    |--------------------------------------------------------------------------
    | Admin area path
    |--------------------------------------------------------------------------
    */

    'admin_path' => env('NUVABILL_ADMIN_PATH', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | For public demo sites only. Shows the demo sign-ins, locks settings that
    | could lock visitors out or reach other servers, sends no email, and
    | replaces all data with fresh demo data every hour.
    |
    */

    'demo' => (bool) env('NUVABILL_DEMO', false),

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    |
    | Releases are read from GitHub. Every release zip must come with a .sig
    | file signed by the Nuvabill release key; the public half is below.
    |
    */

    'updates' => [
        'repository' => env('NUVABILL_UPDATE_REPOSITORY', 'meroxis/nuvabill'),
        'api_url' => env('NUVABILL_UPDATE_API_URL', 'https://api.github.com'),
        'public_key' => env('NUVABILL_UPDATE_PUBLIC_KEY', 'vH3YQXmgUOPpSb5UCWOWn0PT43HrQ76RQUFUnw8Emgw='),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketplace
    |--------------------------------------------------------------------------
    |
    | Themes, order forms and extensions come from the marketplace store. Every
    | package is signed by the store; the public half of its key is below and
    | nothing installs from the marketplace without a valid signature.
    |
    | "store" is only true on the marketplace store itself (my.nuvabill.com):
    | it turns on the catalog API, license keys, developer accounts, reviews
    | and payouts.
    |
    */

    'marketplace' => [
        'url' => rtrim((string) env('NUVABILL_MARKETPLACE_URL', 'https://my.nuvabill.com'), '/'),
        'public_key' => env('NUVABILL_MARKETPLACE_PUBLIC_KEY', 'ZQ8NK5APeVMJvCJ/8cs6D+R7/X3trvQdMNizLINm3MQ='),
        'store' => (bool) env('NUVABILL_MARKETPLACE_STORE', false),
        'signing_key_path' => env('NUVABILL_MARKETPLACE_SIGNING_KEY_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    */

    'extensions_path' => base_path('extensions'),

    'themes_path' => base_path('themes'),

    'orderforms_path' => base_path('orderforms'),

];

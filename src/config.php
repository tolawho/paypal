<?php

return [

    /**
     * Set our Sandbox and Live credentials
     */
    'client_id' => env('PAYPAL_CLIENT_ID', ''),
    'secret' => env('PAYPAL_SECRET', ''),

    'currency' => 'USD',

    /**
     * List plan id
     */
    'plans' => [
        'standard' => env('PAYPAL_STANDARD_PLAN', ''),
        'sliver' => env('PAYPAL_STANDARD_SILVER', ''),
        'gold' => env('PAYPAL_STANDARD_GOLD', ''),
        'platinum' => env('PAYPAL_STANDARD_PLATINUM', ''),
    ],

    /**
     * SDK configuration settings
     */
    'settings' => [

        /**
         * Payment Mode
         *
         * Available options are 'sandbox' or 'live'
         */
        'mode' => env('PAYPAL_MODE', 'sandbox'),

        // Specify the max connection attempt (3000 = 3 seconds)
        'http.ConnectionTimeOut' => 3000,

        // Specify whether or not we want to store logs
        'log.LogEnabled' => true,

        // Specify the location for our paypal logs
        'log.FileName' => storage_path() . '/logs/paypal-' . date('Y-m-d') . '.log',

        /**
         * Log Level
         *
         * Available options: 'DEBUG', 'INFO', 'WARN' or 'ERROR'
         *
         * Logging is most verbose in the DEBUG level and decreases
         * as you proceed towards ERROR. WARN or ERROR would be a
         * recommended option for live environments.
         */
        'log.LogLevel' => 'DEBUG'

    ]
];
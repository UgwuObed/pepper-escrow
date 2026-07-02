<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This is the default payment gateway used when creating escrow transactions
    | with card payment. Each API client can override this with their own
    | gateway preference.
    |
    | Supported: 'paystack', 'stripe', 'seerbit'
    |
    */
    'default_gateway' => env('ESCROW_DEFAULT_GATEWAY', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Transaction Statuses
    |--------------------------------------------------------------------------
    */
    'statuses' => [
        'pending' => 'Pending',
        'open' => 'Open',
        'cancelled' => 'Cancelled',
        'flagged' => 'Flagged',
        'fulfilled' => 'Fulfilled',
        'closed' => 'Closed',
        'payment_pending' => 'PaymentPending',
        'payment_failed' => 'PaymentFailed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees
    |--------------------------------------------------------------------------
    */
    'fee_percentage' => env('ESCROW_FEE_PERCENTAGE', 2.5),

    /*
    |--------------------------------------------------------------------------
    | Callback URLs
    |--------------------------------------------------------------------------
    */
    'callback_url' => env('ESCROW_CALLBACK_URL', '/escrow/callback'),
];

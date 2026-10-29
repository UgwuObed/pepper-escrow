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
    | Supported: 'paystack', 'stripe', 'seerbit', 'flutterwave'
    |
    | Note: VFD is deliberately absent here — it is a banking rail with no
    | hosted checkout. It is selected for the two gateways below instead.
    |
    */
    'default_gateway' => env('ESCROW_DEFAULT_GATEWAY', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Default Virtual Account Gateway
    |--------------------------------------------------------------------------
    |
    | Which provider issues the account number a customer pays into. This is
    | resolved independently of the card gateway above, so a merchant can
    | collect bank transfers through VFD while cards still run on Paystack.
    |
    | Supported: 'vfd', 'paystack', 'flutterwave'
    |
    */
    'default_va_gateway' => env('ESCROW_DEFAULT_VA_GATEWAY', 'vfd'),

    /*
    |--------------------------------------------------------------------------
    | Default Settlement Gateway
    |--------------------------------------------------------------------------
    |
    | Which provider pays merchants and vendors out. Also resolved separately
    | from the card gateway.
    |
    | Supported: 'vfd', 'paystack', 'flutterwave'
    |
    */
    'default_settlement_gateway' => env('ESCROW_DEFAULT_SETTLEMENT_GATEWAY', 'vfd'),

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

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paystack Configuration
    |--------------------------------------------------------------------------
    */
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Configuration
    |--------------------------------------------------------------------------
    */
    'stripe' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Connect Configuration
    |--------------------------------------------------------------------------
    |
    | Conditional settlement: the buyer is charged on the seller's connected
    | account, which is held on manual payouts until the rules engine releases
    | it. Shares the platform API keys with 'stripe' above.
    |
    | Connect account and payout events arrive on their own endpoint secret,
    | separate from ordinary platform events.
    |
    */
    'stripe_connect' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'platform_country' => env('STRIPE_PLATFORM_COUNTRY', 'GB'),
        'onboarding_refresh_url' => env('STRIPE_ONBOARDING_REFRESH_URL'),
        'onboarding_return_url' => env('STRIPE_ONBOARDING_RETURN_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SeerBit Configuration
    |--------------------------------------------------------------------------
    */
    'seerbit' => [
        'public_key' => env('SEERBIT_PUBLIC_KEY'),
        'private_key' => env('SEERBIT_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flutterwave Configuration
    |--------------------------------------------------------------------------
    */
    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | VFD Microfinance Bank (VBaaS) Configuration
    |--------------------------------------------------------------------------
    |
    | Virtual accounts, name enquiry and payouts. Auth lives on a different
    | host to the wallet API, hence the two base URLs.
    |
    | VFD does not sign webhooks, so VFD_WEBHOOK_SECRET is the shared secret we
    | embed in the callback URL registration; VFD_WEBHOOK_IPS optionally pins
    | the source addresses. Credit notifications are rejected without a secret.
    |
    */
    'vfd' => [
        'consumer_key' => env('VFD_CONSUMER_KEY'),
        'consumer_secret' => env('VFD_CONSUMER_SECRET'),
        'base_url' => env('VFD_BASE_URL', 'https://api-devapps.vfdbank.systems/vtech-wallet/api/v2/wallet2'),
        'auth_url' => env('VFD_AUTH_URL', 'https://api-devapps.vfdbank.systems/vfd-tech/baas-portal/v1.1'),
        'pool_account' => env('VFD_POOL_ACCOUNT'),
        'validity_time' => env('VFD_TOKEN_VALIDITY', 60),
        'webhook_secret' => env('VFD_WEBHOOK_SECRET'),
        'webhook_ips' => env('VFD_WEBHOOK_IPS', ''),
    ],
];

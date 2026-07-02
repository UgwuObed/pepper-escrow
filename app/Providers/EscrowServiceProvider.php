<?php

namespace App\Providers;

use App\PaymentGateways\FlutterwaveGateway;
use App\PaymentGateways\PaystackGateway;
use App\PaymentGateways\SeerBitGateway;
use App\PaymentGateways\StripeGateway;
use App\Services\EscrowPaymentService;
use Illuminate\Support\ServiceProvider;

class EscrowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EscrowPaymentService::class, function ($app) {
            $service = new EscrowPaymentService();

            if (config('gateways.paystack.secret_key')) {
                $service->registerGateway('paystack', new PaystackGateway(config('gateways.paystack')));
            }

            if (config('gateways.stripe.secret_key')) {
                $service->registerGateway('stripe', new StripeGateway(config('gateways.stripe')));
            }

            if (config('gateways.seerbit.public_key')) {
                $service->registerGateway('seerbit', new SeerBitGateway(config('gateways.seerbit')));
            }

            if (config('gateways.flutterwave.secret_key')) {
                $service->registerGateway('flutterwave', new FlutterwaveGateway(config('gateways.flutterwave')));
            }

            return $service;
        });
    }

    public function boot(): void
    {
        //
    }
}

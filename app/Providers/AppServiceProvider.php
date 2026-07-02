<?php

namespace App\Providers;

use App\Services\CommissionService;
use App\Services\TenantService;
use App\Services\WalletService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantService::class);
        $this->app->singleton(WalletService::class);
        $this->app->singleton(CommissionService::class);
    }

    public function boot(): void
    {
        //
    }
}

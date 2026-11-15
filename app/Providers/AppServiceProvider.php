<?php

namespace App\Providers;

use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Views use Bootstrap 5; the default Tailwind pagination renders unstyled.
        Paginator::useBootstrapFive();
    }
}

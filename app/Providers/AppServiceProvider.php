<?php

namespace App\Providers;

use App\Services\StripeService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeService::class, function ($app) {
            return new StripeService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
       URL::forceScheme('https');
    }
}

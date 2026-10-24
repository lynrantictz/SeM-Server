<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Business\Vendor::observe(\App\Observers\Operations\ReviewObserver::class);
        \App\Models\Business\Business::observe(\App\Observers\Operations\ReviewObserver::class);
        \App\Models\Business\ComplianceDocument::observe(\App\Observers\Operations\ReviewObserver::class);
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use App\Services\PriceScannerSupplierProvider;
use App\Services\ScanContext;
use App\Services\SupplierSearchProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ScanContext::class);
        $this->app->bind(SupplierSearchProvider::class, PriceScannerSupplierProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->isAdmin());
    }
}

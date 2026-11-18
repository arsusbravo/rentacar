<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
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
        // MySQL 5.5 limits InnoDB index keys to 767 bytes; utf8mb4 needs 4 bytes/char,
        // so indexed strings must be <= 191 chars.
        Schema::defaultStringLength(191);
    }
}

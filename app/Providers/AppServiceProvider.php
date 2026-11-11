<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Gate;
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
        // SUPERADMIN is allowed everything without being enumerated.
        //
        // Returning null rather than false on a miss is the whole point: false
        // would be a verdict and would stop every other gate, policy and
        // permission check from ever being consulted.
        Gate::before(function (User $user): ?bool {
            return $user->hasRole(Roles::SUPERADMIN) ? true : null;
        });
    }
}

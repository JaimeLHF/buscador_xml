<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Payloads de jobs podem conter dados de clientes: acesso só para operadores listados em HORIZON_ADMINS.
        Gate::define('viewHorizon', function ($user = null) {
            $admins = array_filter(array_map('trim', explode(',', (string) config('services.horizon.admins'))));

            return $user !== null && in_array(strtolower($user->email), array_map('strtolower', $admins), true);
        });
    }
}

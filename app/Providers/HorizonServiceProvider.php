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
     * Configure the Horizon authorization services.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request) {
            // Allow access in local environment
            if (app()->environment('local')) {
                return true;
            }

            // For production - you can customize this logic
            // Option 1: Allow anyone (temporary for testing)
            return true;

            // Option 2: Check for specific email or role
            // return $request->user() && 
            //        in_array($request->user()->email, [
            //            'your-email@example.com',
            //        ]);

            // Option 3: Check if user is authenticated
            // return $request->user() !== null;
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // Allow access in local environment
            if (app()->environment('local')) {
                return true;
            }

            // For production - customize as needed
            return true; // Temporary - allows anyone

            // Example: Only allow specific users
            // return $user && in_array($user->email, [
            //     'your-email@example.com',
            // ]);
        });
    }
}
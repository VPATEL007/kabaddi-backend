<?php

namespace App\Providers;

use App\Services\Broadcast\BroadcastPublisher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so several feed triggers in one request collapse into one publish.
        $this->app->singleton(BroadcastPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('broadcast-partner', function (Request $request) {
            $partner = $request->attributes->get('broadcast_partner');

            return $partner
                ? Limit::perMinute($partner->rate_limit ?: 60)->by('partner:' . $partner->id)
                : Limit::perMinute(10)->by($request->ip());
        });
    }
}

<?php

namespace App\Providers;

use App\Services\JwtService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtService::class, function ($app) {
            $config = $app['config']['jwt'];

            return new JwtService(
                secret: $config['secret'],
                algo: $config['algo'],
                ttl: (int) $config['ttl'],
                issuer: $config['issuer'],
            );
        });
    }

    public function boot(): void
    {
        //
    }
}

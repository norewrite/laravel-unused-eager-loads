<?php

namespace NoRewrite\UnusedEagerLoads;

use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use NoRewrite\UnusedEagerLoads\Http\Middleware\TrackUnusedEagerLoads;
use NoRewrite\UnusedEagerLoads\Reporting\RelationUsageReporter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

final class UnusedEagerLoadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/unused-eager-loads.php',
            'unused-eager-loads'
        );

        $this->app->scoped(RelationUsageTracker::class, function ($app) {
            return new RelationUsageTracker((array) $app['config']->get('unused-eager-loads', []));
        });

        $this->app->scoped(RelationUsageReporter::class, function ($app) {
            return new RelationUsageReporter(
                $app->make(LogManager::class),
                (array) $app['config']->get('unused-eager-loads', [])
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/unused-eager-loads.php' => config_path('unused-eager-loads.php'),
        ], 'unused-eager-loads-config');

        if (! (bool) config('unused-eager-loads.enabled', false)) {
            return;
        }

        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TrackUnusedEagerLoads::class);
            }
        }
    }
}

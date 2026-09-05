<?php

namespace NoRewrite\UnusedEagerLoads;

use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use NoRewrite\UnusedEagerLoads\Http\Middleware\TrackUnusedEagerLoads;
use NoRewrite\UnusedEagerLoads\Reporting\RelationUsageReporter;

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

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/unused-eager-loads.php' => config_path('unused-eager-loads.php'),
        ], 'unused-eager-loads-config');

        if (! (bool) config('unused-eager-loads.enabled', false)) {
            return;
        }

        if (! (bool) config('unused-eager-loads.middleware.auto_register', true)) {
            return;
        }

        foreach ((array) config('unused-eager-loads.middleware.groups', ['web']) as $group) {
            $router->prependMiddlewareToGroup((string) $group, TrackUnusedEagerLoads::class);
        }
    }
}

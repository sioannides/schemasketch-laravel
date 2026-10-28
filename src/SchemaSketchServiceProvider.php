<?php

namespace SchemaSketch\Laravel;

use Illuminate\Support\ServiceProvider;

class SchemaSketchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schemasketch.php', 'schemasketch');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/schemasketch.php' => config_path('schemasketch.php'),
            ], 'schemasketch-config');

            $this->commands([
                Commands\PullCommand::class,
            ]);
        }
    }
}

<?php

namespace SchemaSketch\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SchemaSketch\Laravel\SchemaSketchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SchemaSketchServiceProvider::class];
    }

    // SQLite in memory by default. CI also runs the suite against real MySQL
    // and PostgreSQL by setting DB_CONNECTION (see .github/workflows/tests.yml),
    // because each driver reports schema details differently.
    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION');
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        // Every test drops all tables afterwards, so never point the suite
        // at a database that isn't obviously a test database.
        $database = getenv('DB_DATABASE') ?: 'testing';
        if (! str_contains(strtolower($database), 'test')) {
            throw new \RuntimeException("Refusing to run tests against \"{$database}\": the suite drops all tables. Use a database whose name contains \"test\".");
        }

        $app['config']->set('database.default', $driver);
        $app['config']->set("database.connections.{$driver}", array_merge(
            $app['config']->get("database.connections.{$driver}"),
            [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
                'database' => $database,
                'username' => getenv('DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
                'password' => getenv('DB_PASSWORD') ?: 'password',
            ],
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        // Each test creates its own tables; start every test from an empty
        // database on MySQL/PostgreSQL too (SQLite in memory is always fresh).
        $this->beforeApplicationDestroyed(function () {
            $this->app['db']->connection()->getSchemaBuilder()->dropAllTables();
        });
    }
}

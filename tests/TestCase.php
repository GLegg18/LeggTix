<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // RefreshDatabase/migration tests drop tables. Refuse any non-test target
        // before Laravel begins its database test lifecycle.
        $environment = $app->environment();
        $connection = $app['config']->get('database.default');
        $driver = $app['config']->get('database.connections.mysql.driver');
        $database = $app['config']->get('database.connections.mysql.database');

        if (! $app->environment('testing')
            || $connection !== 'mysql'
            || $driver !== 'mysql'
            || ! is_string($database)
            || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Unsafe test configuration: environment=%s, connection=%s, driver=%s, database=%s. Tests require APP_ENV=testing and a dedicated MySQL database ending in _test. Run .\\scripts\\test.ps1 for isolated setup; cached application configuration is not used there.',
                $environment,
                is_string($connection) ? $connection : get_debug_type($connection),
                is_string($driver) ? $driver : get_debug_type($driver),
                is_string($database) ? $database : get_debug_type($database),
            ));
        }

        try {
            $actualDatabase = DB::selectOne('SELECT DATABASE() AS name')->name;
        } catch (QueryException $exception) {
            throw new RuntimeException(
                'Test MySQL connection failed before migrations. Run .\\scripts\\test.ps1 to provision and clean up isolated MySQL automatically. Manual runners need a separate test database and access to it.',
                previous: $exception,
            );
        }

        if ($actualDatabase !== $database) {
            throw new RuntimeException(sprintf(
                'Test database mismatch: configured=%s, connected=%s. Tests stopped before migrations; run .\\scripts\\test.ps1 for isolated setup.',
                $database,
                is_string($actualDatabase) ? $actualDatabase : get_debug_type($actualDatabase),
            ));
        }

        return $app;
    }
}

<?php

namespace Tests;

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
        $database = $app['config']->get('database.connections.mysql.database');

        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'mysql'
            || ! is_string($database)
            || ! str_ends_with($database, '_test')
            || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
            throw new RuntimeException('Tests require APP_ENV=testing and a dedicated MySQL database ending in _test.');
        }

        return $app;
    }
}

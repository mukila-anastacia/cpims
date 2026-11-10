<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Stop at once if the tests would touch anything other than the temporary in-memory database. */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException(
                'Tests must use the in-memory SQLite database. Check phpunit.xml. Stopped to protect real data.'
            );
        }
    }
}
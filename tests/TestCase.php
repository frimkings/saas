<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! app()->environment('testing') || ! in_array($database, ['eyeclinicproject_testing', 'eyeclinicproject_testing_lens_imports'], true)) {
            throw new \RuntimeException(
                "Refusing to run tests against unsafe database [{$database}] on connection [{$connection}]."
            );
        }

        return parent::setUpTraits();
    }
}

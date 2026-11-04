<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application and refuse to continue unless the default connection is in-memory SQLite,
     * so tests that refresh the database can never wipe a real database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests must run on in-memory SQLite, but the default connection is [{$connection}] with database [{$database}]. Check phpunit.xml and the environment variables."
            );
        }

        return $app;
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests require SQLite :memory:. Clear cached configuration before running tests; refusing to touch a persistent database.');
        }
        return $app;
    }
}

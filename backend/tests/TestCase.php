<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: RefreshDatabase wipes the database, so never run against
     * anything but a dedicated *_test database.
     */
    protected function beforeRefreshingDatabase()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}].");
        }
    }

    /**
     * Simulates a request from the Next.js frontend so Sanctum treats it as a
     * stateful (cookie/session) request.
     */
    protected function fromFrontend(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
            'Accept' => 'application/json',
        ]);
    }
}

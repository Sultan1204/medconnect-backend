<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * For tests that need *committed* data - e.g. several database connections
 * or child processes racing each other. Tables are truncated before each
 * test instead of wrapping it in a transaction.
 *
 * Integration tests run after the Feature suite (see phpunit.xml order).
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /** Never truncate the migration bookkeeping table. */
    protected $exceptTables = ['migrations'];
}

<?php

namespace Tests;

use Database\Seeders\TestSupportSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * The document types and the roles are seeded for every test that refreshes
     * the database. Neither is sample data: a tracking code takes its
     * abbreviation from the document's type, so nothing can be uploaded without
     * the types, and every module route is permission-gated, so nothing can be
     * reached without the roles.
     */
    protected bool $seed = true;

    /**
     * @var class-string<\Illuminate\Database\Seeder>
     */
    protected string $seeder = TestSupportSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // The permission registrar caches the whole permission map in memory
        // for the life of the process. Without this, the second test in a file
        // resolves roles against the first test's now-rolled-back rows.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

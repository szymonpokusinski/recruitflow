<?php

namespace Tests;

use App\Integrations\Contracts\CredentialStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InMemoryCredentialStore;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Automated tests must never write test credentials into the user's
        // Keychain or persistent credential files. Individual tests may override.
        $this->app->instance(CredentialStore::class,
            new InMemoryCredentialStore);
    }
}

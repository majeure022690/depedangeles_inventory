<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Timebox;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sanctum only starts a session for API requests that come from a
     * stateful frontend origin, as the SPA's do.
     *
     * @var array<string, string>
     */
    protected $defaultHeaders = [
        'Referer' => 'http://localhost:5173/',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The array cache store lives for the whole PHPUnit process, not
        // per test: SystemSetting values, 2FA tokens and Spatie's
        // permission cache would otherwise leak between tests.
        Cache::flush();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // The login timebox pads every failed login to a fixed minimum; skip
        // the padding so failed-login tests stay fast. PrimeLoginTest
        // re-binds the real Timebox to assert the padding itself.
        $this->app->bind(Timebox::class, fn () => new class extends Timebox
        {
            protected function usleep(int $microseconds): void {}
        });
    }
}

<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fortify and passkeys are gone (ADR §4): none of their routes may answer.
 */
class UnusedPackageRoutesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function removedRoutes(): array
    {
        return [
            'register page' => ['GET', '/register'],
            'register submit' => ['POST', '/register'],
            'web login' => ['POST', '/login'],
            'web logout' => ['POST', '/logout'],
            'email verification notice' => ['GET', '/email/verify'],
            'email verification resend' => ['POST', '/email/verification-notification'],
            'confirm password page' => ['GET', '/user/confirm-password'],
            'confirm password submit' => ['POST', '/user/confirm-password'],
            'two-factor challenge page' => ['GET', '/two-factor-challenge'],
            'two-factor challenge submit' => ['POST', '/two-factor-challenge'],
            'enable two-factor' => ['POST', '/user/two-factor-authentication'],
            'two-factor recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
            'passkey options' => ['GET', '/passkeys/options'],
            'passkey login' => ['POST', '/passkeys/login'],
            'passkey well-known' => ['GET', '/.well-known/passkey-endpoints'],
            'settings profile' => ['GET', '/settings/profile'],
            'settings security' => ['GET', '/settings/security'],
            'settings password' => ['PUT', '/settings/password'],
            'self-service account deletion' => ['DELETE', '/settings/profile'],
        ];
    }

    #[DataProvider('removedRoutes')]
    public function test_removed_auth_routes_are_not_exposed(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertNotFound();
    }
}

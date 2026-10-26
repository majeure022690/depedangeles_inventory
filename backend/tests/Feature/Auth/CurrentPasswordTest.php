<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Models\User;
use App\Rules\CurrentPassword;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

/**
 * Password re-confirmation checks PRIME; only allowlisted developer
 * accounts are checked against their local password.
 */
class CurrentPasswordTest extends TestCase
{
    use CreatesUsersWithRoles, FakesPrime, RefreshDatabase;

    private const PRIME_PASSWORD = 'PrimePass123!';

    private const LOCAL_PASSWORD = 'LocalPass123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();
    }

    private function user(string $email = 'juan@deped.gov.ph'): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make(self::LOCAL_PASSWORD)]);
    }

    private function inPrime(string $email = 'juan@deped.gov.ph', string $status = 'Active'): void
    {
        $this->prime('users')->insert([
            'full_name' => 'Juan', 'email_address' => $email, 'password' => Hash::make(self::PRIME_PASSWORD), 'user_status' => $status,
        ]);
    }

    /**
     * @return list<string>
     */
    private function errors(User $user, string $password): array
    {
        return Validator::make(['current_password' => $password], ['current_password' => [new CurrentPassword($user)]])
            ->errors()->get('current_password');
    }

    public function test_prime_user_is_checked_against_prime_not_the_local_copy(): void
    {
        $user = $this->user();
        $this->inPrime();

        $this->assertSame([], $this->errors($user, self::PRIME_PASSWORD));
        $this->assertSame(['The password is incorrect.'], $this->errors($user, self::LOCAL_PASSWORD));
        $this->assertSame(['The password is incorrect.'], $this->errors($user, 'wrong'));
    }

    public function test_account_unknown_to_prime_or_refused_by_prime_fails(): void
    {
        $unknown = $this->user('ghost@example.test');
        $refused = $this->user();
        $this->inPrime(status: 'Deactivated');

        $this->assertSame(['The password is incorrect.'], $this->errors($unknown, self::LOCAL_PASSWORD));
        $this->assertSame(['The password is incorrect.'], $this->errors($refused, self::PRIME_PASSWORD));
    }

    public function test_prime_unreachable_fails_with_a_clear_message(): void
    {
        $user = $this->user();
        $this->breakPrime();

        $this->assertSame(
            ["Your password can't be checked right now because PRIME is unavailable. Please try again later."],
            $this->errors($user, self::PRIME_PASSWORD),
        );
    }

    public function test_allowlisted_account_is_checked_against_its_local_password_even_with_prime_down(): void
    {
        $user = $this->user('dev@example.test');
        config(['auth.local_login_emails' => ['dev@example.test']]);
        $this->breakPrime();

        $this->assertSame([], $this->errors($user, self::LOCAL_PASSWORD));
        $this->assertSame(['The password is incorrect.'], $this->errors($user, 'wrong'));
    }

    public function test_allowlisted_account_known_to_prime_uses_prime_and_never_falls_back(): void
    {
        $user = $this->user('dev@example.test');
        $this->inPrime('dev@example.test');
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->assertSame([], $this->errors($user, self::PRIME_PASSWORD));
        $this->assertSame(['The password is incorrect.'], $this->errors($user, self::LOCAL_PASSWORD));
    }

    public function test_allowlisted_account_not_in_prime_uses_its_local_password(): void
    {
        $user = $this->user('dev@example.test');
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->assertSame([], $this->errors($user, self::LOCAL_PASSWORD));
    }

    public function test_several_prime_rows_for_the_email_fail(): void
    {
        $user = $this->user();
        $this->inPrime();
        $this->inPrime();

        $this->assertSame(['The password is incorrect.'], $this->errors($user, self::PRIME_PASSWORD));
    }

    public function test_allowlisted_account_without_a_local_password_fails(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.test', 'password' => null]);
        config(['auth.local_login_emails' => ['dev@example.test']]);

        $this->assertSame(['The password is incorrect.'], $this->errors($user, self::LOCAL_PASSWORD));
    }

    public function test_endpoints_accept_the_prime_password_under_the_current_password_key(): void
    {
        $admin = $this->userWithPermissions([Permission::SecuritySettingsManage], ['email' => 'juan@deped.gov.ph', 'password' => Hash::make(self::LOCAL_PASSWORD)]);
        $this->inPrime();

        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => self::LOCAL_PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'The password is incorrect.']);

        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => self::PRIME_PASSWORD])
            ->assertOk();
    }
}

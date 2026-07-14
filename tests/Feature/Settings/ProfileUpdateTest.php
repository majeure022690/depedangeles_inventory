<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }

    /**
     * Regression coverage for the 2026-07 security review's Finding #2:
     * an account holding `users.manage` (e.g. an Administrator) could
     * self-delete via this same page with no admin review, which is
     * exactly the account whose disappearance can leave the system
     * unadministered. ProfileDeleteRequest now denies self-deletion for
     * any such account; non-privileged accounts are unaffected (see
     * test_user_can_delete_their_account above).
     */
    public function test_users_manage_permission_holder_cannot_delete_their_own_account(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::where('name', 'division-ict-admin')->firstOrFail());

        $response = $this
            ->actingAs($admin)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response->assertForbidden();
        $this->assertNotNull($admin->fresh());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.self_delete_blocked',
            'actor_id' => $admin->id,
        ]);
    }

    /**
     * Regression coverage for Finding #2's audit-integrity fix:
     * `audit_logs.actor_id` is nullOnDelete, so a self-deleted account
     * would otherwise erase its own attribution from every row it
     * produced. AuditLog::record() now snapshots the actor's name/email
     * into `properties.actor_snapshot` at write time, so the row stays
     * meaningful even after `actor_id` nulls out.
     */
    public function test_deleting_account_preserves_actor_identity_in_audit_log_snapshot(): void
    {
        $user = User::factory()->create(['name' => 'Audit Trail Test', 'email' => 'audit-trail@example.com']);

        // Any authenticated action that writes an audit row with this
        // user as actor — role self-management is the simplest one
        // available without a fixture Personnel/Equipment record.
        $this->actingAs($user)->get(route('profile.edit'));
        AuditLog::record('test.actor_snapshot_probe', $user);

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password']);

        $this->assertGuest();
        $this->assertNull($user->fresh());

        $row = AuditLog::where('action', 'test.actor_snapshot_probe')->firstOrFail();

        $this->assertNull($row->actor_id);
        $this->assertSame('Audit Trail Test', $row->properties['actor_snapshot']['name']);
        $this->assertSame('audit-trail@example.com', $row->properties['actor_snapshot']['email']);
    }
}

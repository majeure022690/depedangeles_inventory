<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-07 security review's Finding #1: open
 * self-registration exposed real DepEd Personnel PII to anyone who could
 * click their own emailed verification link — no admin vetting.
 *
 * The fix is CreateNewUser assigning the zero-permission 'pending' role
 * (never 'viewer') to every self-registration, so a registrant is
 * authenticated-and-verified but can access nothing until a Division ICT
 * Admin reviews the account and assigns a real role. These tests prove
 * that end-to-end through the actual HTTP routes and Policies — not just
 * "a role with no permissions exists", but that it actually 403s.
 */
class RegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_new_registration_is_assigned_the_pending_role_with_zero_permissions(): void
    {
        $this->post(route('register.store'), [
            'name' => 'New Registrant',
            'email' => 'new-registrant@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'new-registrant@example.com')->firstOrFail();

        $this->assertSame(['pending'], $user->roles()->pluck('name')->all());
        $this->assertSame([], $user->permissionNames());
    }

    public function test_verified_pending_user_is_denied_access_to_personnel_and_equipment(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'pending')->firstOrFail());

        $this->actingAs($user)->get(route('personnel.index'))->assertForbidden();
        $this->actingAs($user)->get(route('personnel.create'))->assertForbidden();
        $this->actingAs($user)->get(route('equipment.index'))->assertForbidden();
        $this->actingAs($user)->get(route('equipment.create'))->assertForbidden();

        // The dashboard itself carries no sensitive data, so a pending
        // user landing there (rather than a hard error) is fine — the
        // access-control boundary is the data, not the login.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_admin_approval_by_assigning_a_role_grants_the_matching_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'pending')->firstOrFail());

        // Simulates a Division ICT Admin's approval action (no UI yet —
        // see the forward-looking note in the security review; this is
        // the same User::assignRole()/removeRole() a future users.manage
        // UI would call).
        $user->removeRole('pending');
        $user->assignRole('viewer');

        $this->assertSame([
            'personnel.view', 'equipment.view', 'isp_accounts.view',
            'stakeholder_profile.view', 'internet_connectivity.view',
        ], $user->permissionNames());

        $this->actingAs($user)->get(route('personnel.index'))->assertOk();
        $this->actingAs($user)->get(route('personnel.create'))->assertForbidden();
    }
}

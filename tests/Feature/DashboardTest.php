<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\Permission;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Dashboard has no dedicated permission of its own — it's a personalized
 * summary of whatever the requesting user already has view access to (see
 * DashboardController's doc-comment) — so the core thing this suite
 * verifies is per-section prop *omission*, not a 403: a zero-permission
 * 'pending' user gets a mostly-empty page, never an error, and each
 * section only appears once its corresponding view permission is granted,
 * independently of the others.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_pending_user_with_no_permissions_gets_every_section_omitted(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('pending');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->missing('equipment')
            ->missing('personnel')
            ->missing('ispAccounts')
            ->missing('recentActivity')
        );
    }

    public function test_sections_are_gated_independently_by_their_own_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $equipmentOnly = Role::create(['name' => 'equipment-view-only', 'label' => 'Equipment View Only']);
        $equipmentOnly->permissions()->attach(
            Permission::where('name', PermissionEnum::EquipmentView->value)->firstOrFail(),
        );

        $user = User::factory()->create();
        $user->assignRole($equipmentOnly);

        $response = $this->actingAs($user)->get(route('dashboard'));

        // equipment.view grants both 'equipment' and the equipment-backed
        // 'recentActivity' feed, but personnel/isp_accounts stay omitted
        // since this role holds neither of those permissions.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->has('equipment')
            ->has('recentActivity')
            ->missing('personnel')
            ->missing('ispAccounts')
        );
    }

    public function test_admin_dashboard_reports_accurate_summaries_for_every_section(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('division-ict-admin');

        $serviceable = Equipment::factory()->create([
            'disposition_status' => 'Normal',
            'non_functional' => false,
            'under_warranty' => false,
            'end_warranty_date' => null,
            'acquisition_cost' => 1000,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        Equipment::factory()->create([
            'disposition_status' => 'Normal',
            'non_functional' => false,
            'under_warranty' => false,
            'end_warranty_date' => null,
            'acquisition_cost' => 1500,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        Equipment::factory()->create([
            'disposition_status' => 'Normal',
            'non_functional' => true,
            'under_warranty' => true,
            'end_warranty_date' => now()->addDays(10)->toDateString(),
            'acquisition_cost' => 500,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        // Outside the 30-day window — must NOT count as expiring soon.
        Equipment::factory()->create([
            'disposition_status' => 'Normal',
            'non_functional' => false,
            'under_warranty' => true,
            'end_warranty_date' => now()->addDays(90)->toDateString(),
            'acquisition_cost' => 750,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);

        $serviceable->transactions()->create(['transaction_type' => 'Beginning Inventory']);

        Personnel::factory()->create(['inactive' => false, 'oic' => true]);
        Personnel::factory()->create(['inactive' => false, 'oic' => false]);
        Personnel::factory()->create(['inactive' => true, 'oic' => false]);

        IspAccount::factory()->create([
            'inactive_contract' => false,
            'cost_per_month' => 2000,
            'contract_end_date' => now()->addDays(5)->toDateString(),
        ]);
        IspAccount::factory()->create([
            'inactive_contract' => false,
            'cost_per_month' => 3000,
            'contract_end_date' => now()->addDays(90)->toDateString(),
        ]);
        // Inactive contracts must not count toward monthly cost or the
        // expiring-soon alert.
        IspAccount::factory()->create([
            'inactive_contract' => true,
            'cost_per_month' => 999999,
            'contract_end_date' => now()->addDays(1)->toDateString(),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('equipment.total', 4)
            ->where('equipment.non_functional_count', 1)
            ->where('equipment.warranty_expiring_soon_count', 1)
            // Compared via closure, not a bare float literal: a whole-
            // number float (3750.0) round-trips through JSON as `3750`,
            // which json_decode()s back as an int — a strict === against
            // a float literal would fail on the type, not the value.
            ->where('equipment.total_acquisition_cost', fn ($value) => (float) $value === 3750.0)
            ->where('personnel.active_count', 2)
            ->where('personnel.inactive_count', 1)
            ->where('personnel.oic_count', 1)
            ->where('ispAccounts.total_monthly_cost', fn ($value) => (float) $value === 5000.0)
            ->where('ispAccounts.contracts_expiring_soon_count', 1)
            ->has('recentActivity', 1)
            ->where('recentActivity.0.transaction_type', 'Beginning Inventory')
        );
    }
}

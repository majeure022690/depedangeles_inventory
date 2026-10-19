<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * The dashboard has no permission of its own: each section is present
 * only when the user holds that resource's `.view` permission, and a
 * missing permission omits the section rather than 403ing.
 */
class DashboardTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_a_user_with_no_role_gets_an_empty_summary(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertExactJson(['data' => [], 'message' => 'Success']);
    }

    public function test_pending_user_gets_every_section_omitted(): void
    {
        $response = $this->actingAs($this->userWithRole('pending'))
            ->getJson('/api/dashboard/summary')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertDatabaseMissing('activity_log', ['event' => 'authorization.denied']);
    }

    public function test_sections_are_gated_independently_by_their_own_permission(): void
    {
        $user = $this->userWithPermissions([Permission::EquipmentView]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk();

        $this->assertEqualsCanonicalizing(['equipment', 'recent_transactions'], array_keys($response->json('data')));
    }

    public function test_admin_dashboard_reports_accurate_summaries_for_every_section(): void
    {
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
        // Outside the 30-day window: not "expiring soon".
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

        IspAccount::factory()->create(['inactive_contract' => false, 'cost_per_month' => 2000, 'contract_end_date' => now()->addDays(5)->toDateString()]);
        IspAccount::factory()->create(['inactive_contract' => false, 'cost_per_month' => 3000, 'contract_end_date' => now()->addDays(90)->toDateString()]);
        // Inactive contracts count toward neither the cost nor the alert.
        IspAccount::factory()->create(['inactive_contract' => true, 'cost_per_month' => 999999, 'contract_end_date' => now()->addDays(1)->toDateString()]);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.equipment.total', 4)
            ->assertJsonPath('data.equipment.non_functional_count', 1)
            ->assertJsonPath('data.equipment.warranty_expiring_soon_count', 1)
            ->assertJsonPath('data.personnel.active_count', 2)
            ->assertJsonPath('data.personnel.inactive_count', 1)
            ->assertJsonPath('data.personnel.oic_count', 1)
            ->assertJsonPath('data.isp_accounts.contracts_expiring_soon_count', 1)
            ->assertJsonCount(1, 'data.recent_transactions')
            ->assertJsonPath('data.recent_transactions.0.transaction_type', 'Beginning Inventory')
            ->assertJsonPath('data.recent_transactions.0.equipment.id', $serviceable->id);

        // Whole-number floats come back from JSON as ints.
        $this->assertEquals(3750, $response->json('data.equipment.total_acquisition_cost'));
        $this->assertEquals(5000, $response->json('data.isp_accounts.total_monthly_cost'));
    }
}

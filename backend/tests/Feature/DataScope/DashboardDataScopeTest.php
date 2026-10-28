<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class DashboardDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $other = Office::factory()->create();

        $colleague = Personnel::factory()->create(['office_id' => $this->office->id]);
        $outsider = Personnel::factory()->create(['office_id' => $other->id]);

        IspAccount::factory()->create(['office_id' => $this->office->id, 'cost_per_month' => 1000, 'inactive_contract' => false, 'mode_of_acquisition' => 'DepEd Purchase']);
        IspAccount::factory()->create(['office_id' => $other->id, 'cost_per_month' => 2000, 'inactive_contract' => false, 'mode_of_acquisition' => 'DepEd Purchase']);
        IspAccount::factory()->create(['office_id' => null, 'cost_per_month' => 4000, 'inactive_contract' => false, 'mode_of_acquisition' => 'DepEd Purchase']);

        $laptop = ItemType::where('name', 'Laptop')->valueOrFail('id');
        Equipment::factory()->create(['item_type_id' => $laptop, 'acquisition_cost' => 60000, 'current_accountable_officer_id' => $colleague->id, 'current_end_user_id' => null]);
        Equipment::factory()->create(['item_type_id' => $laptop, 'acquisition_cost' => 70000, 'current_accountable_officer_id' => $outsider->id, 'current_end_user_id' => null]);
    }

    private function summary(DataScope $scope): TestResponse
    {
        $user = $this->userWithPermissions(
            [Permission::DashboardPpe, Permission::DashboardEquipmentOverview, Permission::DashboardEquipmentDistribution, Permission::DashboardInternetConnectivity],
            ['office_id' => $this->office->id],
            $scope,
        );

        return $this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk();
    }

    public function test_school_scope_summaries_cover_only_the_users_office(): void
    {
        $this->summary(DataScope::School)
            ->assertJsonPath('data.ppe', ['total_value' => 60000, 'item_count' => 1])
            ->assertJsonPath('data.equipment_overview.groups.1.count', 1)
            ->assertJsonPath('data.equipment_distribution.total', 1)
            ->assertJsonPath('data.internet_connectivity.total_accounts', 1)
            ->assertJsonPath('data.internet_connectivity.total_cost', 1000);
    }

    public function test_own_scope_without_a_link_sees_zeroes_but_office_isp_accounts(): void
    {
        $this->summary(DataScope::Own)
            ->assertJsonPath('data.ppe', ['total_value' => 0, 'item_count' => 0])
            ->assertJsonPath('data.equipment_distribution.total', 0)
            ->assertJsonPath('data.internet_connectivity.total_cost', 1000);
    }

    public function test_all_scope_summaries_cover_everything(): void
    {
        $this->summary(DataScope::All)
            ->assertJsonPath('data.ppe', ['total_value' => 130000, 'item_count' => 2])
            ->assertJsonPath('data.equipment_overview.groups.1.count', 2)
            ->assertJsonPath('data.internet_connectivity.total_accounts', 3)
            ->assertJsonPath('data.internet_connectivity.total_cost', 7000);
    }
}

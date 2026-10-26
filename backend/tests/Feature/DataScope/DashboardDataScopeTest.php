<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class DashboardDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Equipment $inReach;

    private Equipment $outOfReach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $other = Office::factory()->create();

        $colleague = Personnel::factory()->create(['office_id' => $this->office->id, 'inactive' => false, 'oic' => true]);
        Personnel::factory()->create(['office_id' => $this->office->id, 'inactive' => true, 'oic' => false]);
        $outsider = Personnel::factory()->create(['office_id' => $other->id, 'inactive' => false, 'oic' => true]);
        Personnel::factory()->count(2)->create(['office_id' => $other->id, 'inactive' => false, 'oic' => false]);

        IspAccount::factory()->create(['office_id' => $this->office->id, 'cost_per_month' => 1000, 'inactive_contract' => false]);
        IspAccount::factory()->create(['office_id' => $other->id, 'cost_per_month' => 2000, 'inactive_contract' => false]);
        IspAccount::factory()->create(['office_id' => null, 'cost_per_month' => 4000, 'inactive_contract' => false]);

        $this->inReach = Equipment::factory()->create(['current_accountable_officer_id' => $colleague->id, 'current_end_user_id' => null]);
        $this->outOfReach = Equipment::factory()->create(['current_accountable_officer_id' => $outsider->id, 'current_end_user_id' => null]);
        $this->inReach->transactions()->create(['transaction_type' => 'Beginning Inventory']);
        $this->outOfReach->transactions()->create(['transaction_type' => 'Beginning Inventory']);
    }

    private function user(DataScope $scope): User
    {
        return $this->userWithPermissions(
            [Permission::EquipmentView, Permission::PersonnelView, Permission::IspAccountsView],
            ['office_id' => $this->office->id],
            $scope,
        );
    }

    public function test_school_scope_summaries_cover_only_the_users_office(): void
    {
        $response = $this->actingAs($this->user(DataScope::School))->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.equipment.total', 1)
            ->assertJsonPath('data.personnel.active_count', 1)
            ->assertJsonPath('data.personnel.inactive_count', 1)
            ->assertJsonPath('data.personnel.oic_count', 1)
            ->assertJsonPath('data.isp_accounts.total_monthly_cost', 1000);

        $this->assertSame([$this->inReach->id], collect($response->json('data.recent_transactions'))->pluck('equipment.id')->all());
    }

    public function test_own_scope_without_a_link_sees_zeroes_but_office_isp_accounts(): void
    {
        $response = $this->actingAs($this->user(DataScope::Own))->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.equipment.total', 0)
            ->assertJsonPath('data.personnel.active_count', 0)
            ->assertJsonPath('data.isp_accounts.total_monthly_cost', 1000);

        $this->assertSame([], $response->json('data.recent_transactions'));
    }

    public function test_all_scope_summaries_cover_everything(): void
    {
        $response = $this->actingAs($this->user(DataScope::All))->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.equipment.total', 2)
            ->assertJsonPath('data.personnel.active_count', 4)
            ->assertJsonPath('data.isp_accounts.total_monthly_cost', 7000);

        $this->assertCount(2, $response->json('data.recent_transactions'));
    }
}

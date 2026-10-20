<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private Office $school;

    private Personnel $me;

    private Personnel $colleague;

    private Equipment $mine;

    private Equipment $colleagues;

    private Equipment $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->school = Office::factory()->create();
        $this->me = Personnel::factory()->create(['deped_email' => 'Me@DepEd.gov.ph', 'office_id' => $this->school->id]);
        $this->colleague = Personnel::factory()->create(['office_id' => $this->school->id]);

        $this->mine = Equipment::factory()->create(['current_accountable_officer_id' => $this->me->id, 'acquisition_cost' => 1000]);
        $this->colleagues = Equipment::factory()->create(['current_end_user_id' => $this->colleague->id, 'acquisition_cost' => 2000]);
        $this->elsewhere = Equipment::factory()->create(['acquisition_cost' => 4000]);
    }

    private function userWithScope(DataScope $scope): User
    {
        $role = Role::create(['name' => 'scoped_'.$scope->value, 'label' => 'Scoped', 'data_scope' => $scope]);
        $role->givePermissionTo(Permission::EquipmentView->value, Permission::EquipmentEdit->value);

        $user = User::factory()->create(['email' => 'me@deped.gov.ph', 'office_id' => $this->school->id]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/equipment')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_own_scope_lists_only_items_issued_to_the_users_personnel_record(): void
    {
        $this->assertSame([$this->mine->id], $this->listedIds($this->userWithScope(DataScope::Own)));
    }

    public function test_school_scope_lists_items_issued_to_anyone_at_the_users_office(): void
    {
        $this->assertSame([$this->mine->id, $this->colleagues->id], $this->listedIds($this->userWithScope(DataScope::School)));
    }

    public function test_all_scope_lists_everything(): void
    {
        $this->assertCount(3, $this->listedIds($this->userWithScope(DataScope::All)));
    }

    public function test_out_of_scope_records_are_forbidden_by_id(): void
    {
        $user = $this->userWithScope(DataScope::Own);

        $this->actingAs($user)->getJson("/api/equipment/{$this->mine->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/equipment/{$this->elsewhere->id}")->assertForbidden();
        $this->actingAs($user)->get("/api/equipment/{$this->elsewhere->id}/qr-code")->assertForbidden();
        $this->actingAs($user)->putJson("/api/equipment/{$this->elsewhere->id}", [])->assertForbidden();
    }

    public function test_dashboard_totals_cover_only_the_users_scope(): void
    {
        $this->actingAs($this->userWithScope(DataScope::Own))->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.equipment.total', 1)
            ->assertJsonPath('data.equipment.total_acquisition_cost', 1000);
    }

    public function test_user_without_roles_or_matching_personnel_sees_nothing(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::EquipmentView->value);

        $this->assertSame([], $this->listedIds($user));
    }

    public function test_viewer_defaults_to_own_and_super_administrator_is_always_all(): void
    {
        $this->assertSame(DataScope::Own, Role::findByName('viewer')->data_scope);

        $admin = User::factory()->create();
        $admin->assignRole(Role::SUPER_ADMINISTRATOR);
        $superAdmin = Role::findByName(Role::SUPER_ADMINISTRATOR);

        $this->actingAs($admin)->putJson("/api/roles/{$superAdmin->id}", [
            'name' => $superAdmin->name,
            'label' => $superAdmin->label,
            'data_scope' => 'own',
            'permissions' => array_map(fn (Permission $p) => $p->value, Permission::cases()),
        ])->assertOk();

        $this->assertSame(DataScope::All, $superAdmin->fresh()->data_scope);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\TestCase;

class EquipmentDataScopeTest extends TestCase
{
    use BuildsEquipmentPayloads, RefreshDatabase;

    private Office $school;

    private Office $otherSchool;

    private Personnel $me;

    private Personnel $colleague;

    private Personnel $outsider;

    private Equipment $mine;

    private Equipment $colleagues;

    private Equipment $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->school = Office::factory()->create();
        $this->otherSchool = Office::factory()->create();
        $this->me = Personnel::factory()->create(['deped_email' => 'someone-else@deped.gov.ph', 'office_id' => $this->school->id]);
        $this->colleague = Personnel::factory()->create(['office_id' => $this->school->id]);
        $this->outsider = Personnel::factory()->create(['office_id' => $this->otherSchool->id]);

        $this->mine = Equipment::factory()->create(['current_accountable_officer_id' => $this->me->id, 'current_end_user_id' => null, 'acquisition_cost' => 1000]);
        $this->colleagues = Equipment::factory()->create(['current_accountable_officer_id' => null, 'current_end_user_id' => $this->colleague->id, 'acquisition_cost' => 2000]);
        $this->elsewhere = Equipment::factory()->create(['current_accountable_officer_id' => $this->outsider->id, 'current_end_user_id' => $this->outsider->id, 'acquisition_cost' => 4000]);
    }

    /**
     * @param  list<Permission>  $extra
     */
    private function userWithScope(DataScope $scope, array $extra = [], bool $linked = true, ?Office $office = null): User
    {
        $role = Role::create(['name' => 'scoped_'.uniqid(), 'label' => 'Scoped', 'data_scope' => $scope]);
        $role->givePermissionTo(array_map(fn (Permission $p) => $p->value, [Permission::EquipmentView, Permission::EquipmentEdit, ...$extra]));

        $user = User::factory()->create(['email' => $linked ? 'me@deped.gov.ph' : fake()->unique()->safeEmail(), 'office_id' => ($office ?? $this->school)->id]);
        $user->assignRole($role);

        if ($linked) {
            $user->forceFill(['employee_no' => $this->me->employee_id])->save();
        }

        return $user;
    }

    private function unissued(?User $creator): Equipment
    {
        return Equipment::factory()->create([
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
            'created_by_user_id' => $creator?->id,
        ]);
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user->fresh())->getJson('/api/equipment')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_own_scope_lists_only_items_issued_to_the_users_linked_personnel_record(): void
    {
        $this->assertSame([$this->mine->id], $this->listedIds($this->userWithScope(DataScope::Own)));
    }

    public function test_deped_email_no_longer_grants_access_without_the_explicit_link(): void
    {
        $this->me->update(['deped_email' => 'me@deped.gov.ph']);
        $user = $this->userWithScope(DataScope::Own, linked: false);

        $this->assertSame([], $this->listedIds($user));
        $this->actingAs($user)->getJson("/api/equipment/{$this->mine->id}")->assertNotFound();
    }

    public function test_school_scope_lists_items_issued_to_anyone_at_the_users_office(): void
    {
        $this->assertSame([$this->mine->id, $this->colleagues->id], $this->listedIds($this->userWithScope(DataScope::School)));
    }

    public function test_school_scope_without_a_link_still_reaches_the_office(): void
    {
        $this->assertSame([$this->mine->id, $this->colleagues->id], $this->listedIds($this->userWithScope(DataScope::School, linked: false)));
    }

    public function test_school_scope_reaches_the_linked_record_even_when_it_is_at_another_office(): void
    {
        $user = $this->userWithScope(DataScope::School, office: $this->otherSchool);

        $this->assertSame([$this->mine->id, $this->elsewhere->id], $this->listedIds($user));
    }

    public function test_all_scope_lists_everything(): void
    {
        $this->assertCount(3, $this->listedIds($this->userWithScope(DataScope::All)));
    }

    public function test_out_of_scope_records_are_not_found_by_id(): void
    {
        $user = $this->userWithScope(DataScope::Own, [Permission::EquipmentDelete, Permission::EquipmentTransactionsCreate]);

        $this->actingAs($user)->getJson("/api/equipment/{$this->mine->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/equipment/{$this->elsewhere->id}")->assertNotFound();
        $this->actingAs($user)->get("/api/equipment/{$this->elsewhere->id}/qr-code")->assertNotFound();
        $this->actingAs($user)->putJson("/api/equipment/{$this->elsewhere->id}", [])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/equipment/{$this->elsewhere->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/equipment/{$this->elsewhere->id}/transactions", [
            'transaction_type' => 'Beginning Inventory',
        ])->assertNotFound();

        $this->assertNotSoftDeleted($this->elsewhere);
        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_missing_permission_is_still_403_even_for_out_of_scope_records(): void
    {
        $role = Role::create(['name' => 'view_only_own', 'label' => 'View only', 'data_scope' => DataScope::Own]);
        $role->givePermissionTo(Permission::EquipmentView->value);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->putJson("/api/equipment/{$this->elsewhere->id}", [])->assertForbidden();
        $this->actingAs($user)->postJson("/api/equipment/{$this->elsewhere->id}/transactions", ['transaction_type' => 'Return'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/equipment/{$this->elsewhere->id}")->assertForbidden();

    }

    public function test_dashboard_totals_cover_only_the_users_scope(): void
    {
        $this->actingAs($this->userWithScope(DataScope::Own, [Permission::DashboardSemiExpendable]))->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.semi_expendable', ['total_value' => 1000, 'item_count' => 1]);
    }

    public function test_user_without_roles_or_link_sees_nothing(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::EquipmentView->value);

        $this->assertSame([], $this->listedIds($user));
    }

    public function test_store_records_the_creator(): void
    {
        $user = $this->userWithScope(DataScope::Own, [Permission::EquipmentCreate]);

        $id = $this->actingAs($user)->postJson('/api/equipment', $this->newEquipmentPayload([
            'created_by_user_id' => 999999,
        ], $this->me))->assertCreated()->json('data.id');

        $this->assertSame($user->id, Equipment::findOrFail($id)->created_by_user_id);
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$id}")->assertOk();
    }

    public function test_store_accepts_only_accountability_personnel_within_the_users_scope(): void
    {
        $user = $this->userWithScope(DataScope::School, [Permission::EquipmentCreate]);

        $listed = array_column($this->actingAs($user)->getJson('/api/equipment/options')->assertOk()->json('data.personnel'), 'value');
        $this->assertContains($this->colleague->id, $listed);
        $this->assertNotContains($this->outsider->id, $listed);

        $this->actingAs($user)->postJson('/api/equipment', $this->newEquipmentPayload([
            'accountability' => $this->accountabilityFields($this->colleague, ['received_by_id' => $this->outsider->id]),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accountability.received_by_id' => 'Choose someone within your access.'])
            ->assertJsonMissingValidationErrors(['accountability.accountable_officer_id', 'accountability.end_user_id']);

        $this->actingAs($user)->postJson('/api/equipment', $this->newEquipmentPayload([], $this->colleague))
            ->assertCreated()
            ->assertJsonPath('data.current_accountable_officer.id', $this->colleague->id);
    }

    public function test_unissued_items_are_visible_to_their_creator_under_own_scope(): void
    {
        $user = $this->userWithScope(DataScope::Own);
        $created = $this->unissued($user);
        $someoneElses = $this->unissued(User::factory()->create(['office_id' => $this->school->id]));
        $orphan = $this->unissued(null);

        $this->assertSame([$this->mine->id, $created->id], $this->listedIds($user));
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$created->id}")->assertOk();
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$someoneElses->id}")->assertNotFound();
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$orphan->id}")->assertNotFound();
    }

    public function test_school_scope_sees_unissued_items_created_by_anyone_at_the_same_office(): void
    {
        $user = $this->userWithScope(DataScope::School);
        $sameOffice = $this->unissued(User::factory()->create(['office_id' => $this->school->id]));
        $otherOffice = $this->unissued(User::factory()->create(['office_id' => $this->otherSchool->id]));
        $noOffice = $this->unissued(User::factory()->create(['office_id' => null]));

        $listed = $this->listedIds($user);

        $this->assertContains($sameOffice->id, $listed);
        $this->assertNotContains($otherOffice->id, $listed);
        $this->assertNotContains($noOffice->id, $listed);
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$sameOffice->id}")->assertOk();
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$otherOffice->id}")->assertNotFound();
    }

    public function test_creator_reach_does_not_apply_once_the_item_is_issued_out_of_reach(): void
    {
        $user = $this->userWithScope(DataScope::Own);
        $issuedAway = Equipment::factory()->create([
            'current_accountable_officer_id' => $this->outsider->id,
            'current_end_user_id' => null,
            'created_by_user_id' => $user->id,
        ]);

        $this->assertNotContains($issuedAway->id, $this->listedIds($user));
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$issuedAway->id}")->assertNotFound();
    }

    public function test_options_personnel_list_is_limited_to_reachable_personnel(): void
    {
        $school = $this->userWithScope(DataScope::School, [Permission::EquipmentTransactionsCreate]);

        $ids = collect($this->actingAs($school)->getJson('/api/equipment/options')->assertOk()->json('data.personnel'))
            ->pluck('value')->sort()->values()->all();

        $this->assertSame(collect([$this->me->id, $this->colleague->id])->sort()->values()->all(), $ids);

        $all = $this->userWithScope(DataScope::All, [Permission::EquipmentTransactionsCreate], linked: false);
        $this->assertContains($this->outsider->id, collect($this->actingAs($all)->getJson('/api/equipment/options')->json('data.personnel'))->pluck('value')->all());
    }

    public function test_scoped_transfers_only_accept_reachable_personnel(): void
    {
        $user = $this->userWithScope(DataScope::School, [Permission::EquipmentTransactionsCreate]);

        foreach (['accountable_officer_id', 'end_user_id', 'received_by_id'] as $field) {
            $this->actingAs($user->fresh())->postJson("/api/equipment/{$this->mine->id}/transactions", [
                'transaction_type' => 'Issuance/Transfer',
                $field => $this->outsider->id,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors($field)
                ->assertJsonPath("errors.{$field}.0", 'Choose someone within your access.');
        }

        $this->assertSame(0, EquipmentTransaction::count());
        $this->assertSame($this->me->id, $this->mine->fresh()->current_accountable_officer_id);

        $this->actingAs($user->fresh())->postJson("/api/equipment/{$this->mine->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->colleague->id,
            'end_user_id' => $this->me->id,
            'received_by_id' => $this->colleague->id,
        ])->assertCreated();

        $this->assertSame($this->colleague->id, $this->mine->fresh()->current_accountable_officer_id);
    }

    public function test_all_scope_transfers_accept_any_personnel(): void
    {
        $user = $this->userWithScope(DataScope::All, [Permission::EquipmentTransactionsCreate], linked: false);

        $this->actingAs($user)->postJson("/api/equipment/{$this->mine->id}/transactions", [
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->outsider->id,
        ])->assertCreated();
    }

    public function test_scoped_transfer_errors_do_not_reveal_whether_a_personnel_id_exists(): void
    {
        $user = $this->userWithScope(DataScope::School, [Permission::EquipmentTransactionsCreate]);
        $trashed = Personnel::factory()->create(['office_id' => $this->school->id]);
        $trashed->delete();

        $errors = [];
        foreach (['out of reach' => $this->outsider->id, 'nonexistent' => 999999, 'soft-deleted' => $trashed->id] as $case => $id) {
            $errors[$case] = $this->actingAs($user->fresh())->postJson("/api/equipment/{$this->mine->id}/transactions", [
                'transaction_type' => 'Issuance/Transfer',
                'accountable_officer_id' => $id,
            ])->assertUnprocessable()->json('errors');
        }

        $this->assertSame(['accountable_officer_id' => ['Choose someone within your access.']], $errors['out of reach']);
        $this->assertSame($errors['out of reach'], $errors['nonexistent']);
        $this->assertSame($errors['out of reach'], $errors['soft-deleted']);
        $this->assertSame(0, EquipmentTransaction::count());
    }

    public function test_all_scope_transfers_reject_soft_deleted_personnel(): void
    {
        $user = $this->userWithScope(DataScope::All, [Permission::EquipmentTransactionsCreate], linked: false);
        $this->outsider->delete();

        foreach (['accountable_officer_id', 'end_user_id', 'received_by_id'] as $field) {
            $this->actingAs($user)->postJson("/api/equipment/{$this->mine->id}/transactions", [
                'transaction_type' => 'Issuance/Transfer',
                $field => $this->outsider->id,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, EquipmentTransaction::count());
        $this->assertSame($this->me->id, $this->mine->fresh()->current_accountable_officer_id);
    }

    public function test_returning_an_item_to_nobody_falls_back_to_creator_reach(): void
    {
        $creator = User::factory()->create(['office_id' => $this->otherSchool->id]);
        $this->mine->forceFill(['created_by_user_id' => $creator->id])->saveQuietly();
        $user = $this->userWithScope(DataScope::School, [Permission::EquipmentTransactionsCreate]);

        $this->actingAs($user->fresh())->postJson("/api/equipment/{$this->mine->id}/transactions", [
            'transaction_type' => 'Return',
        ])->assertCreated();

        $this->assertNull($this->mine->fresh()->current_accountable_officer_id);
        $this->actingAs($user->fresh())->getJson("/api/equipment/{$this->mine->id}")->assertNotFound();
    }

    public function test_super_administrator_is_always_all(): void
    {
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

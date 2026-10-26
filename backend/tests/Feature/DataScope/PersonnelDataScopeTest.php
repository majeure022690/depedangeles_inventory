<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class PersonnelDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Office $otherOffice;

    private Personnel $colleague;

    private Personnel $outsider;

    private Personnel $unassigned;

    private const WRITE = [Permission::PersonnelView, Permission::PersonnelCreate, Permission::PersonnelEdit, Permission::PersonnelDelete];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $this->otherOffice = Office::factory()->create();
        $this->colleague = Personnel::factory()->create(['office_id' => $this->office->id]);
        $this->outsider = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $this->unassigned = Personnel::factory()->create(['office_id' => null]);
    }

    private function scoped(DataScope $scope, ?Office $office = null, bool $withOffice = true): User
    {
        return $this->userWithPermissions(self::WRITE, ['office_id' => $withOffice ? ($office ?? $this->office)->id : null], $scope);
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user->fresh())->getJson('/api/personnel?status=all')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['employee_id' => 'EMP-'.uniqid(), 'last_name' => 'Santos', 'first_name' => 'Maria'], $overrides);
    }

    public function test_own_scope_reaches_only_the_linked_record(): void
    {
        $mine = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $user = $this->scoped(DataScope::Own);
        $user->forceFill(['employee_no' => $mine->employee_id])->save();

        $this->assertSame([$mine->id], $this->listedIds($user));
        $this->actingAs($user->fresh())->getJson("/api/personnel/{$mine->id}")->assertOk()->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.user');
        $this->actingAs($user->fresh())->getJson("/api/personnel/{$this->colleague->id}")->assertNotFound();
    }

    public function test_own_scope_without_a_link_reaches_nothing(): void
    {
        $this->assertSame([], $this->listedIds($this->scoped(DataScope::Own)));
    }

    public function test_school_scope_reaches_the_office_and_the_linked_record(): void
    {
        $mine = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $user = $this->scoped(DataScope::School);
        $user->forceFill(['employee_no' => $mine->employee_id])->save();

        $this->assertSame(collect([$this->colleague->id, $mine->id])->sort()->values()->all(), $this->listedIds($user));
        $this->actingAs($user->fresh())->getJson("/api/personnel/{$this->outsider->id}")->assertNotFound();
        $this->actingAs($user->fresh())->getJson("/api/personnel/{$this->unassigned->id}")->assertNotFound();
    }

    public function test_school_scope_without_an_office_reaches_only_the_link(): void
    {
        $user = $this->scoped(DataScope::School, withOffice: false);

        $this->assertSame([], $this->listedIds($user));
        $this->actingAs($user->fresh())->getJson("/api/personnel/{$this->unassigned->id}")->assertNotFound();
    }

    public function test_all_scope_reaches_everyone_including_unassigned(): void
    {
        $this->assertCount(3, $this->listedIds($this->scoped(DataScope::All, withOffice: false)));
    }

    public function test_out_of_reach_writes_are_404_and_change_nothing(): void
    {
        $user = $this->scoped(DataScope::School);

        $this->actingAs($user)->putJson("/api/personnel/{$this->outsider->id}", $this->payload())->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/personnel/{$this->outsider->id}")->assertNotFound();

        $this->assertSame($this->outsider->last_name, $this->outsider->fresh()->last_name);
        $this->assertNotSoftDeleted($this->outsider);
    }

    public function test_missing_permission_stays_403_regardless_of_reach(): void
    {
        $user = $this->userWithPermissions([Permission::PersonnelView], ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($user)->putJson("/api/personnel/{$this->outsider->id}", $this->payload())->assertForbidden();
        $this->actingAs($user)->putJson("/api/personnel/{$this->colleague->id}", $this->payload())->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/personnel/{$this->outsider->id}")->assertForbidden();
    }

    public function test_own_scope_cannot_create_personnel(): void
    {
        $this->actingAs($this->scoped(DataScope::Own))->postJson('/api/personnel', $this->payload(['employee_id' => 'EMP-OWN']))->assertForbidden();

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-OWN']);
    }

    public function test_school_scope_create_is_forced_into_the_creators_office(): void
    {
        $user = $this->scoped(DataScope::School);

        $id = $this->actingAs($user)->postJson('/api/personnel', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.office_id', $this->office->id)
            ->json('data.id');
        $this->actingAs($user)->postJson('/api/personnel', $this->payload(['office_id' => $this->office->id]))
            ->assertCreated()
            ->assertJsonPath('data.office_id', $this->office->id);

        $this->actingAs($user->fresh())->getJson("/api/personnel/{$id}")->assertOk();
    }

    public function test_school_scope_cannot_create_into_another_office(): void
    {
        $this->actingAs($this->scoped(DataScope::School))
            ->postJson('/api/personnel', $this->payload(['employee_id' => 'EMP-X1', 'office_id' => $this->otherOffice->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-X1']);
    }

    /**
     * @return list<array{DataScope}>
     */
    public static function creatingScopes(): array
    {
        return [[DataScope::School], [DataScope::All]];
    }

    #[DataProvider('creatingScopes')]
    public function test_personnel_create_ignores_a_user_link_for_every_scope(DataScope $scope): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->scoped($scope))
            ->postJson('/api/personnel', $this->payload(['employee_id' => 'EMP-X2', 'user_id' => $target->id]))
            ->assertCreated()
            ->assertJsonMissingPath('data.user_id');

        $this->assertNull($target->fresh()->personnel);
    }

    public function test_school_scope_without_an_office_cannot_create(): void
    {
        $this->actingAs($this->scoped(DataScope::School, withOffice: false))
            ->postJson('/api/personnel', $this->payload(['employee_id' => 'EMP-NO']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('office_id');

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-NO']);
    }

    public function test_scoped_update_cannot_change_office_and_may_resubmit_unchanged(): void
    {
        $user = $this->scoped(DataScope::School);
        $base = ['employee_id' => $this->colleague->employee_id, 'last_name' => 'Renamed', 'first_name' => $this->colleague->first_name];

        $this->actingAs($user)->putJson("/api/personnel/{$this->colleague->id}", $base + ['office_id' => $this->otherOffice->id])
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');
        $this->assertSame($this->office->id, $this->colleague->fresh()->office_id);

        $this->actingAs($user)->putJson("/api/personnel/{$this->colleague->id}", $base + ['office_id' => $this->office->id])
            ->assertOk()->assertJsonPath('data.last_name', 'Renamed');
        $this->actingAs($user)->putJson("/api/personnel/{$this->colleague->id}", $base)->assertOk();
    }

    public function test_all_scope_can_set_office_and_a_sent_user_id_is_ignored(): void
    {
        $admin = $this->scoped(DataScope::All, withOffice: false);
        $account = User::factory()->create(['name' => 'Linked Account']);

        $this->actingAs($admin)->putJson("/api/personnel/{$this->colleague->id}", [
            'employee_id' => $this->colleague->employee_id,
            'last_name' => $this->colleague->last_name,
            'first_name' => $this->colleague->first_name,
            'office_id' => $this->otherOffice->id,
            'user_id' => $account->id,
        ])->assertOk()
            ->assertJsonPath('data.office_id', $this->otherOffice->id)
            ->assertJsonMissingPath('data.user_id');

        $id = $this->actingAs($admin)->postJson('/api/personnel', $this->payload(['office_id' => $this->office->id]))
            ->assertCreated()->json('data.id');
        $this->assertSame($this->office->id, Personnel::findOrFail($id)->office_id);
    }

    public function test_office_must_be_active_unless_it_is_the_records_current_office(): void
    {
        $admin = $this->scoped(DataScope::All);
        $closed = Office::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->postJson('/api/personnel', $this->payload(['office_id' => $closed->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');

        $this->colleague->update(['office_id' => $closed->id]);
        $base = ['employee_id' => $this->colleague->employee_id, 'last_name' => 'Kept', 'first_name' => $this->colleague->first_name];

        $this->actingAs($admin)->putJson("/api/personnel/{$this->colleague->id}", $base + ['office_id' => $closed->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/personnel/{$this->outsider->id}", [
            'employee_id' => $this->outsider->employee_id,
            'last_name' => $this->outsider->last_name,
            'first_name' => $this->outsider->first_name,
            'office_id' => $closed->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('office_id');
    }

    public function test_options_never_include_a_user_list(): void
    {
        $this->actingAs($this->scoped(DataScope::All))->getJson('/api/personnel/options')
            ->assertOk()->assertJsonMissingPath('data.user');
        $this->actingAs($this->scoped(DataScope::School))->getJson('/api/personnel/options')
            ->assertOk()->assertJsonMissingPath('data.user');
    }

    public function test_deleting_a_matched_record_leaves_the_user_without_one(): void
    {
        $admin = $this->scoped(DataScope::All);
        $account = User::factory()->withEmployeeNo($this->colleague->employee_id)->create();

        $this->actingAs($admin)->deleteJson("/api/personnel/{$this->colleague->id}")->assertNoContent();

        $this->assertSoftDeleted($this->colleague);
        $this->assertNull($account->fresh()->personnel);
    }
}

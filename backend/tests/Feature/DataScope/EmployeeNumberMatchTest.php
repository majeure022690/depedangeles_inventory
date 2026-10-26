<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EmployeeNumberMatchTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const PERSONNEL_WRITE = [Permission::PersonnelView, Permission::PersonnelEdit];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return $this->userWithAllPermissions([Permission::RolesCreate, Permission::RolesEdit, Permission::RolesDelete]);
    }

    private function target(DataScope $scope = DataScope::Own, array $attributes = []): User
    {
        return $this->userWithPermissions([Permission::PersonnelView, Permission::EquipmentView], $attributes, $scope);
    }

    private function roleIdOf(User $user): int
    {
        return (int) $user->roles()->value('roles.id');
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/personnel?status=all')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function personnelPayload(Personnel $person, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $person->employee_id,
            'last_name' => $person->last_name,
            'first_name' => $person->first_name,
            'office_id' => $person->office_id,
        ], $overrides);
    }

    public function test_own_scope_reaches_only_the_matched_record(): void
    {
        $mine = Personnel::factory()->create(['employee_id' => '0012']);
        Personnel::factory()->create(['employee_id' => '12']);
        Personnel::factory()->create();
        $user = $this->userWithPermissions([Permission::PersonnelView], ['employee_no' => '0012'], DataScope::Own);

        $this->assertSame([$mine->id], $user->scopedPersonnelIds());
        $this->assertSame([$mine->id], $this->listedIds($user));
    }

    public function test_null_employee_number_reaches_nothing_even_with_blank_employee_ids(): void
    {
        Personnel::factory()->create(['employee_id' => '']);
        Personnel::factory()->create();
        $user = $this->userWithPermissions([Permission::PersonnelView], ['employee_no' => null], DataScope::Own);

        $this->assertSame([], $user->scopedPersonnelIds());
        $this->assertSame([], $this->listedIds($user));
    }

    public function test_a_soft_deleted_match_is_not_reached(): void
    {
        $person = Personnel::factory()->create();
        $person->delete();
        $user = $this->userWithPermissions([Permission::PersonnelView], ['employee_no' => $person->employee_id], DataScope::Own);

        $this->assertSame([], $user->scopedPersonnelIds());
        $this->assertNull($user->personnel);
    }

    public function test_school_scope_reaches_its_office_and_the_match(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $colleague = Personnel::factory()->create(['office_id' => $office->id]);
        $mine = Personnel::factory()->create(['office_id' => $other->id]);
        Personnel::factory()->create(['office_id' => $other->id]);
        $user = $this->userWithPermissions([Permission::PersonnelView], ['office_id' => $office->id, 'employee_no' => $mine->employee_id], DataScope::School);

        $this->assertEqualsCanonicalizing([$colleague->id, $mine->id], $this->listedIds($user));
    }

    public function test_current_user_payload_derives_the_personnel_from_the_employee_number(): void
    {
        $person = Personnel::factory()->create();
        $user = $this->target(attributes: ['employee_no' => $person->employee_id]);

        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'own')
            ->assertJsonPath('data.employee_no', $person->employee_id)
            ->assertJsonPath('data.personnel_id', $person->id)
            ->assertJsonPath('data.personnel', ['id' => $person->id, 'full_name' => $person->fresh()->full_name]);

        $unmatched = $this->target(DataScope::All);
        $this->actingAs($unmatched)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.employee_no', null)
            ->assertJsonPath('data.personnel_id', null)
            ->assertJsonPath('data.personnel', null);
    }

    public function test_login_payload_derives_the_personnel_and_scope(): void
    {
        $person = Personnel::factory()->create();
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = User::factory()->twoFactorConfirmed($secret)->withEmployeeNo($person->employee_id)->create(['password' => Hash::make('CorrectHorse123!')]);
        $this->grantPermissions($user, [Permission::PersonnelView, Permission::EquipmentView], DataScope::School);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertOk()->json('two_fa_token');

        $this->postJson('/api/2fa/verify', ['two_fa_token' => $token, 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'school')
            ->assertJsonPath('data.personnel_id', $person->id)
            ->assertJsonPath('data.personnel.id', $person->id);
    }

    public function test_user_resource_shows_employee_number_and_gates_personnel_behind_personnel_view(): void
    {
        $person = Personnel::factory()->create();
        $target = $this->target(DataScope::School, ['employee_no' => $person->employee_id]);
        $withoutPersonnelView = $this->userWithPermissions([Permission::UsersView], [], DataScope::All);

        $row = collect($this->actingAs($withoutPersonnelView)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $target->id);
        $this->assertSame($person->employee_id, $row['employee_no']);
        $this->assertSame($person->id, $row['personnel_id']);
        $this->assertArrayNotHasKey('personnel', $row);
        $this->assertStringNotContainsString((string) $person->fresh()->full_name, (string) json_encode($row));

        $row = collect($this->actingAs($this->admin())->getJson('/api/users?per_page=100')->json('data'))
            ->firstWhere('id', $target->id);
        $this->assertSame(['id' => $person->id, 'full_name' => $person->fresh()->full_name], $row['personnel']);
        $this->assertSame('school', $row['data_scope']);
    }

    public function test_users_options_no_longer_list_personnel(): void
    {
        $this->actingAs($this->admin())->getJson('/api/users/options')
            ->assertOk()->assertJsonMissingPath('data.personnel');
    }

    public function test_put_users_ignores_sent_personnel_office_and_employee_number(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $person = Personnel::factory()->create();
        $target = $this->target(attributes: ['office_id' => $office->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'role_id' => $this->roleIdOf($target),
            'personnel_id' => $person->id,
            'office_id' => $other->id,
            'employee_no' => $person->employee_id,
        ])->assertOk()
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.employee_no', null)
            ->assertJsonPath('data.personnel_id', null);

        $manager = $this->userWithPermissions([
            Permission::UsersView, Permission::UsersEdit, Permission::PersonnelView, Permission::EquipmentView,
        ], ['office_id' => $office->id], DataScope::School);

        foreach ([$other->id, null] as $officeId) {
            $this->actingAs($manager)->putJson("/api/users/{$target->id}", [
                'role_id' => $this->roleIdOf($target),
                'office_id' => $officeId,
                'personnel_id' => $person->id,
            ])->assertOk()->assertJsonPath('data.office_id', $office->id);
        }

        $fresh = $target->fresh();
        $this->assertSame($office->id, $fresh->office_id);
        $this->assertNull($fresh->employee_no);
        $this->assertSame(0, Activity::query()->where('event', 'like', 'user.personnel_%')->count());
    }

    public function test_scoped_actor_cannot_change_an_employee_id(): void
    {
        $office = Office::factory()->create();
        $person = Personnel::factory()->create(['employee_id' => '0012', 'office_id' => $office->id]);
        $editor = $this->userWithPermissions(self::PERSONNEL_WRITE, ['office_id' => $office->id], DataScope::School);

        foreach (['12', '0013', ''] as $changed) {
            $this->actingAs($editor)->putJson("/api/personnel/{$person->id}", $this->personnelPayload($person, ['employee_id' => $changed]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('employee_id');
        }
        $this->assertSame('0012', $person->fresh()->employee_id);

        $this->actingAs($editor)->putJson("/api/personnel/{$person->id}", $this->personnelPayload($person, ['employee_id' => ' 0012 ', 'last_name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.employee_id', '0012')
            ->assertJsonPath('data.last_name', 'Renamed');
    }

    public function test_scoped_actor_change_error_says_all_scope_is_required(): void
    {
        $office = Office::factory()->create();
        $person = Personnel::factory()->create(['employee_id' => '0012', 'office_id' => $office->id]);
        $editor = $this->userWithPermissions(self::PERSONNEL_WRITE, ['office_id' => $office->id], DataScope::School);

        $this->actingAs($editor)->putJson("/api/personnel/{$person->id}", $this->personnelPayload($person, ['employee_id' => '12']))
            ->assertJsonPath('errors.employee_id.0', 'Only users with access to all records can change this.');
    }

    public function test_all_scope_actor_may_change_an_employee_id(): void
    {
        $person = Personnel::factory()->create(['employee_id' => '0012']);
        $admin = $this->userWithPermissions(self::PERSONNEL_WRITE, [], DataScope::All);

        $this->actingAs($admin)->putJson("/api/personnel/{$person->id}", $this->personnelPayload($person, ['employee_id' => '12']))
            ->assertOk()
            ->assertJsonPath('data.employee_id', '12');
    }

    public function test_scoped_actor_without_personnel_edit_is_forbidden(): void
    {
        $office = Office::factory()->create();
        $person = Personnel::factory()->create(['office_id' => $office->id]);
        $reader = $this->userWithPermissions([Permission::PersonnelView], ['office_id' => $office->id], DataScope::School);

        $this->actingAs($reader)->putJson("/api/personnel/{$person->id}", $this->personnelPayload($person))->assertForbidden();
    }

    public function test_personnel_resource_has_no_user_fields(): void
    {
        $person = Personnel::factory()->create();
        User::factory()->withEmployeeNo($person->employee_id)->create(['name' => 'Matched Account']);
        $viewer = $this->userWithPermissions([Permission::PersonnelView, Permission::UsersView], [], DataScope::All);

        $this->actingAs($viewer)->getJson("/api/personnel/{$person->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.user');

        $list = $this->actingAs($viewer)->getJson('/api/personnel?status=all')->assertOk();
        $this->assertArrayNotHasKey('user_id', $list->json('data.0'));
        $this->assertStringNotContainsString('Matched Account', (string) $list->getContent());
    }

    public function test_linked_office_name_is_read_only(): void
    {
        $office = Office::factory()->create(['office_name' => 'Angeles ES']);
        $office->forceFill(['prime_department_id' => 42])->save();
        $editor = $this->userWithPermissions([Permission::OfficeView, Permission::OfficeEdit], [], DataScope::All);

        $this->actingAs($editor)->getJson("/api/offices/{$office->id}")
            ->assertOk()
            ->assertJsonPath('data.prime_department_id', 42)
            ->assertJsonPath('data.managed_by_prime', true);

        $this->actingAs($editor)->putJson("/api/offices/{$office->id}", ['office_name' => 'Renamed ES'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.office_name.0', 'Managed by PRIME.');
        $this->assertSame('Angeles ES', $office->fresh()->office_name);

        $this->actingAs($editor)->putJson("/api/offices/{$office->id}", ['office_name' => 'Angeles ES', 'district' => 'North'])
            ->assertOk()
            ->assertJsonPath('data.district', 'North')
            ->assertJsonPath('data.prime_department_id', 42);
    }

    public function test_prime_department_id_is_never_accepted_from_a_request(): void
    {
        $office = Office::factory()->create(['office_name' => 'Local ES']);
        $editor = $this->userWithPermissions([Permission::OfficeView, Permission::OfficeEdit], [], DataScope::All);

        $this->actingAs($editor)->putJson("/api/offices/{$office->id}", ['office_name' => 'Local ES', 'prime_department_id' => 7])
            ->assertOk()
            ->assertJsonPath('data.prime_department_id', null)
            ->assertJsonPath('data.managed_by_prime', false);
    }

    public function test_iris_only_office_can_be_renamed(): void
    {
        $office = Office::factory()->create(['office_name' => 'Local ES']);
        $editor = $this->userWithPermissions([Permission::OfficeView, Permission::OfficeEdit], [], DataScope::All);

        $this->actingAs($editor)->putJson("/api/offices/{$office->id}", ['office_name' => 'Renamed Local ES'])
            ->assertOk()
            ->assertJsonPath('data.office_name', 'Renamed Local ES')
            ->assertJsonPath('data.managed_by_prime', false);
    }

    public function test_linked_office_cannot_be_deleted_and_the_denial_is_audited(): void
    {
        $office = Office::factory()->create();
        $office->forceFill(['prime_department_id' => 42])->save();
        $deleter = $this->userWithPermissions([Permission::OfficeView, Permission::OfficeDelete], [], DataScope::All);

        $this->actingAs($deleter)->deleteJson("/api/offices/{$office->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Managed by PRIME.');

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'authorization.denied',
            'subject_type' => $office->getMorphClass(),
            'subject_id' => $office->id,
            'causer_id' => $deleter->id,
        ]);

        $unlinked = Office::factory()->create();
        $this->actingAs($deleter)->deleteJson("/api/offices/{$unlinked->id}")->assertNoContent();
    }
}

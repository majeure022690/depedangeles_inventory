<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class UserPersonnelLinkTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Role::create(['name' => 'reader', 'label' => 'Reader', 'data_scope' => DataScope::Own])
            ->givePermissionTo([Permission::PersonnelView->value, Permission::EquipmentView->value]);
        Role::create(['name' => 'writer', 'label' => 'Writer', 'data_scope' => DataScope::All])
            ->givePermissionTo([Permission::PersonnelView->value, Permission::EquipmentView->value, Permission::EquipmentEdit->value]);
        Role::create(['name' => 'office-writer', 'label' => 'Office Writer', 'data_scope' => DataScope::School])
            ->givePermissionTo([Permission::PersonnelView->value, Permission::EquipmentView->value, Permission::EquipmentEdit->value]);
    }

    private function viewerRoleId(): int
    {
        return Role::where('name', 'reader')->value('id');
    }

    private function admin(): User
    {
        return $this->userWithAllPermissions([Permission::RolesCreate, Permission::RolesEdit, Permission::RolesDelete]);
    }

    private function scopedUserManager(DataScope $scope, ?Office $office = null): User
    {
        return $this->userWithPermissions([
            Permission::UsersView, Permission::UsersEdit,
            Permission::PersonnelView, Permission::EquipmentView,
            Permission::StakeholderProfileView, Permission::InternetConnectivityView,
        ], ['office_id' => $office?->id], $scope);
    }

    public function test_all_scope_admin_links_relinks_and_unlinks_personnel(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('reader');
        $first = Personnel::factory()->create();
        $second = Personnel::factory()->create();

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $first->id])
            ->assertOk()
            ->assertJsonPath('data.personnel_id', $first->id)
            ->assertJsonPath('data.personnel.id', $first->id)
            ->assertJsonPath('data.personnel.full_name', $first->fresh()->full_name);
        $this->assertSame($target->id, $first->fresh()->user_id);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $second->id])
            ->assertOk()->assertJsonPath('data.personnel_id', $second->id);
        $this->assertNull($first->fresh()->user_id);
        $this->assertSame($target->id, $second->fresh()->user_id);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId()])
            ->assertOk()->assertJsonPath('data.personnel_id', $second->id);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => null])
            ->assertOk()->assertJsonPath('data.personnel_id', null);
        $this->assertNull($second->fresh()->user_id);
    }

    public function test_a_personnel_record_linked_to_another_user_is_refused(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $target = $this->userWithRole('reader');
        $taken = Personnel::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $taken->id])
            ->assertUnprocessable()->assertJsonValidationErrors('personnel_id');

        $this->assertSame($owner->id, $taken->fresh()->user_id);
    }

    public function test_soft_deleted_or_missing_personnel_cannot_be_linked(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('reader');
        $deleted = Personnel::factory()->create();
        $deleted->delete();

        foreach ([$deleted->id, 999999] as $id) {
            $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('personnel_id');
        }
    }

    public function test_a_failed_link_rolls_back_the_role_change(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $target = $this->userWithRole('reader');
        $taken = Personnel::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'role_id' => Role::where('name', 'writer')->value('id'),
            'personnel_id' => $taken->id,
        ])->assertUnprocessable();

        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_scoped_user_managers_cannot_set_the_link_or_another_office(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $manager = $this->scopedUserManager(DataScope::School, $office);
        $target = $this->userWithRole('reader', ['office_id' => $other->id]);
        $person = Personnel::factory()->create();

        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $person->id])
            ->assertUnprocessable()->assertJsonValidationErrors('personnel_id');
        $this->assertNull($person->fresh()->user_id);

        $third = Office::factory()->create();
        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'office_id' => $third->id])
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');
        $this->assertSame($other->id, $target->fresh()->office_id);

        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'office_id' => $other->id])->assertOk();
        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'office_id' => $office->id])->assertOk();
        $this->assertSame($office->id, $target->fresh()->office_id);
    }

    public function test_scoped_user_managers_cannot_clear_an_existing_link(): void
    {
        $office = Office::factory()->create();
        $manager = $this->scopedUserManager(DataScope::School, $office);
        $target = $this->userWithRole('reader', ['office_id' => $office->id]);
        $person = Personnel::factory()->create(['user_id' => $target->id]);

        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('personnel_id');

        $this->assertSame($target->id, $person->fresh()->user_id);
    }

    public function test_scoped_user_managers_cannot_clear_another_offices_user_by_omitting_office_id(): void
    {
        $manager = $this->scopedUserManager(DataScope::School, Office::factory()->create());
        $other = Office::factory()->create();
        $target = $this->userWithRole('reader', ['office_id' => $other->id]);

        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId()]);

        $this->assertSame($other->id, $target->fresh()->office_id);
    }

    public function test_link_and_unlink_are_audited_on_the_user(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('reader');
        $first = Personnel::factory()->create();
        $second = Personnel::factory()->create();
        $put = fn (?int $id) => $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => $this->viewerRoleId(), 'personnel_id' => $id])->assertOk();

        $put($first->id);
        $put($first->id);
        $put($second->id);
        $put(null);
        $put(null);

        $events = Activity::query()
            ->whereIn('event', ['user.personnel_linked', 'user.personnel_unlinked'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['user.personnel_linked', 'user.personnel_linked', 'user.personnel_unlinked'], $events->pluck('event')->all());
        $this->assertSame([
            ['old_personnel_id' => null, 'new_personnel_id' => $first->id],
            ['old_personnel_id' => $first->id, 'new_personnel_id' => $second->id],
            ['old_personnel_id' => $second->id, 'new_personnel_id' => null],
        ], $events->map(fn (Activity $activity) => $activity->properties->only(['old_personnel_id', 'new_personnel_id'])->all())->all());

        foreach ($events as $event) {
            $this->assertSame(User::class, $event->subject_type);
            $this->assertSame($target->id, $event->subject_id);
            $this->assertSame($admin->id, $event->causer_id);
        }
    }

    public function test_a_link_taken_between_validation_and_save_is_refused_and_rolled_back(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('reader');
        $rival = User::factory()->create();
        $person = Personnel::factory()->create();
        $raced = false;

        DB::listen(function (QueryExecuted $query) use ($person, $rival, &$raced): void {
            if ($raced || ! str_starts_with($query->sql, 'select "user_id" from "personnel"')) {
                return;
            }

            $raced = true;
            DB::table('personnel')->where('id', $person->id)->update(['user_id' => $rival->id]);
        });

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'role_id' => Role::where('name', 'writer')->value('id'),
            'personnel_id' => $person->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.personnel_id.0', 'This personnel record is already linked to another user.');

        $this->assertTrue($raced, 'The race window was never hit; the test no longer exercises the re-check.');
        $this->assertSame($rival->id, $person->fresh()->user_id);
        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertSame(0, Activity::query()->where('event', 'user.personnel_linked')->count());
    }

    public function test_linking_requires_personnel_view_even_for_all_scope_user_managers(): void
    {
        $manager = $this->userWithPermissions([
            Permission::UsersView, Permission::UsersEdit,
            Permission::EquipmentView, Permission::StakeholderProfileView, Permission::InternetConnectivityView,
        ], [], DataScope::All);
        $target = User::factory()->create();
        $target->assignRole($this->userWithPermissions([Permission::EquipmentView], [], DataScope::Own)->roles->first());
        $person = Personnel::factory()->create();

        $this->actingAs($manager)->putJson("/api/users/{$target->id}", ['role_id' => $target->roles->first()->id, 'personnel_id' => $person->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.personnel_id.0', 'Only users with access to all records can change this.');
        $this->assertNull($person->fresh()->user_id);

        $this->actingAs($manager)->getJson('/api/users/options')->assertOk()->assertJsonMissingPath('data.personnel');
    }

    public function test_user_resource_shows_the_linked_personnel_only_to_personnel_view_holders(): void
    {
        $target = $this->userWithRole('reader');
        $person = Personnel::factory()->create(['user_id' => $target->id]);
        $withoutPersonnelView = $this->userWithPermissions([Permission::UsersView], [], DataScope::All);

        $row = collect($this->actingAs($withoutPersonnelView)->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $target->id);
        $this->assertArrayNotHasKey('personnel', $row);
        $this->assertStringNotContainsString((string) $person->fresh()->full_name, (string) json_encode($row));

        $row = collect($this->actingAs($this->admin())->getJson('/api/users?per_page=100')->json('data'))
            ->firstWhere('id', $target->id);
        $this->assertSame(['id' => $person->id, 'full_name' => $person->fresh()->full_name], $row['personnel']);
    }

    public function test_users_options_list_personnel_with_their_link_only_for_all_scope(): void
    {
        $owner = User::factory()->create();
        $linked = Personnel::factory()->create(['user_id' => $owner->id]);

        $rows = collect($this->actingAs($this->admin())->getJson('/api/users/options')
            ->assertOk()->json('data.personnel'));
        $this->assertSame($owner->id, $rows->firstWhere('value', $linked->id)['linked_user_id']);

        $this->actingAs($this->scopedUserManager(DataScope::School, Office::factory()->create()))->getJson('/api/users/options')
            ->assertOk()->assertJsonMissingPath('data.personnel');
    }

    public function test_user_list_shows_the_linked_personnel_and_scope(): void
    {
        $target = $this->userWithRole('office-writer');
        $person = Personnel::factory()->create(['user_id' => $target->id]);

        $row = collect($this->actingAs($this->admin())->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $target->id);

        $this->assertSame($person->id, $row['personnel_id']);
        $this->assertSame('school', $row['data_scope']);
    }

    public function test_current_user_payload_exposes_link_and_scope(): void
    {
        $user = $this->userWithRole('reader');
        $person = Personnel::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'own')
            ->assertJsonPath('data.personnel_id', $person->id)
            ->assertJsonPath('data.personnel', ['id' => $person->id, 'full_name' => $person->fresh()->full_name]);

        $unlinked = $this->userWithRole('writer');
        $this->actingAs($unlinked)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'all')
            ->assertJsonPath('data.personnel_id', null)
            ->assertJsonPath('data.personnel', null);
    }

    public function test_login_payload_exposes_link_and_scope(): void
    {
        $secret = (new TwoFactorAuthService)->generateSecret();
        $user = User::factory()->twoFactorConfirmed($secret)->create(['password' => Hash::make('CorrectHorse123!')]);
        $user->assignRole('office-writer');
        $person = Personnel::factory()->create(['user_id' => $user->id]);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'CorrectHorse123!'])
            ->assertOk()->json('two_fa_token');

        $this->postJson('/api/2fa/verify', ['two_fa_token' => $token, 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'school')
            ->assertJsonPath('data.personnel_id', $person->id)
            ->assertJsonPath('data.personnel.id', $person->id);
    }
}

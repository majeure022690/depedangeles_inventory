<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspSpeedTest;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class AuditLogDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Office $otherOffice;

    private User $colleague;

    private User $stranger;

    private Personnel $me;

    private Personnel $coworker;

    private Personnel $outsider;

    /**
     * @var array<string, array{Model, Model}>
     */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $this->otherOffice = Office::factory()->create();
        $this->colleague = User::factory()->create(['office_id' => $this->office->id]);
        $this->stranger = User::factory()->create(['office_id' => $this->otherOffice->id]);

        $this->me = Personnel::factory()->create(['office_id' => $this->office->id]);
        $this->coworker = Personnel::factory()->create(['office_id' => $this->office->id]);
        $this->outsider = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);

        $myEquipment = $this->equipment($this->me);
        $coworkerEquipment = $this->equipment($this->coworker);
        $outsiderEquipment = $this->equipment($this->outsider);
        $ourIsp = IspAccount::factory()->create(['office_id' => $this->office->id]);
        $theirIsp = IspAccount::factory()->create(['office_id' => $this->otherOffice->id]);

        $this->subjects = [
            'personnel' => [$this->coworker, $this->outsider],
            'equipment' => [$coworkerEquipment, $outsiderEquipment],
            'transaction' => [$this->transaction($coworkerEquipment), $this->transaction($outsiderEquipment)],
            'isp' => [$ourIsp, $theirIsp],
            'speed test' => [IspSpeedTest::factory()->create(['isp_account_id' => $ourIsp->id]), IspSpeedTest::factory()->create(['isp_account_id' => $theirIsp->id])],
            'stakeholder' => [StakeholderProfile::create(['office_id' => $this->office->id]), StakeholderProfile::create(['office_id' => $this->otherOffice->id])],
            'survey' => [InternetConnectivitySurvey::create(['office_id' => $this->office->id]), InternetConnectivitySurvey::create(['office_id' => $this->otherOffice->id])],
            'user' => [$this->colleague, $this->stranger],
        ];

        $this->subjects['my personnel'] = [$this->me, $this->outsider];
        $this->subjects['my equipment'] = [$myEquipment, $outsiderEquipment];

        Activity::query()->delete();
    }

    private function equipment(Personnel $officer): Equipment
    {
        return Equipment::factory()->create(['current_accountable_officer_id' => $officer->id, 'current_end_user_id' => null]);
    }

    private function transaction(Equipment $equipment): EquipmentTransaction
    {
        return EquipmentTransaction::query()->create(['equipment_id' => $equipment->id, 'transaction_type' => 'Issuance-Transfer']);
    }

    private function auditor(DataScope $scope, ?int $officeId): User
    {
        $actor = $this->userWithPermissions([Permission::AuditLogView], ['office_id' => $officeId], $scope);
        $actor->forceFill(['employee_no' => $this->me->employee_id])->save();
        Activity::query()->delete();

        return $actor;
    }

    private function record(string $event, ?Model $subject = null, ?User $causer = null): int
    {
        $logger = activity()->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $activity = $logger->log($event);
        $this->assertInstanceOf(Activity::class, $activity);

        return (int) $activity->id;
    }

    /**
     * @return array{in: array<string, int>, out: array<string, int>}
     */
    private function recordSubjectRows(): array
    {
        $rows = ['in' => [], 'out' => []];

        foreach ($this->subjects as $label => [$inside, $outside]) {
            $rows['in'][$label] = $this->record("{$label}.updated", $inside, $this->stranger);
            $rows['out'][$label] = $this->record("{$label}.updated", $outside, $this->stranger);
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $actor, string $query = ''): array
    {
        return collect($this->actingAs($actor)->getJson('/api/audit-log?per_page=100'.$query)->assertOk()->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_office_scope_sees_rows_about_records_in_its_office_only(): void
    {
        $actor = $this->auditor(DataScope::School, $this->office->id);
        $rows = $this->recordSubjectRows();

        $ids = $this->visibleIds($actor);

        foreach ($rows['in'] as $label => $id) {
            $this->assertContains($id, $ids, "{$label} inside reach should be visible");
        }

        foreach ($rows['out'] as $label => $id) {
            $this->assertNotContains($id, $ids, "{$label} outside reach should be hidden");
        }
    }

    public function test_own_scope_sees_only_its_linked_personnel_and_their_equipment(): void
    {
        $actor = $this->auditor(DataScope::Own, $this->office->id);
        $rows = $this->recordSubjectRows();

        $ids = $this->visibleIds($actor);

        foreach (['my personnel', 'my equipment', 'isp', 'speed test', 'stakeholder', 'survey', 'user'] as $label) {
            $this->assertContains($rows['in'][$label], $ids, "{$label} should be visible");
        }

        foreach (['personnel', 'equipment', 'transaction'] as $label) {
            $this->assertNotContains($rows['in'][$label], $ids, "colleague's {$label} should be hidden");
        }

        foreach ($rows['out'] as $label => $id) {
            $this->assertNotContains($id, $ids, "{$label} outside reach should be hidden");
        }
    }

    public function test_scoped_actor_without_an_office_sees_only_its_own_actions_and_linked_records(): void
    {
        $actor = $this->auditor(DataScope::School, null);
        $rows = $this->recordSubjectRows();
        $mine = $this->record('role.updated', Role::query()->firstOrFail(), $actor);

        $ids = $this->visibleIds($actor);

        $this->assertContains($mine, $ids);
        $this->assertContains($rows['in']['my personnel'], $ids);

        foreach (['personnel', 'isp', 'stakeholder', 'survey', 'user'] as $label) {
            $this->assertNotContains($rows['in'][$label], $ids, "{$label} should be hidden without an office");
        }
    }

    public function test_scoped_actor_always_sees_its_own_actions(): void
    {
        $actor = $this->auditor(DataScope::School, $this->office->id);

        $ownRows = [
            $this->record('role.updated', Role::query()->firstOrFail(), $actor),
            $this->record('office.updated', $this->otherOffice, $actor),
            $this->record('personnel.updated', $this->outsider, $actor),
            $this->record('authorization.denied', $this->outsider, $actor),
            $this->record('email_log.cleared', null, $actor),
        ];

        $ids = $this->visibleIds($actor);

        foreach ($ownRows as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_scoped_actor_does_not_see_admin_reference_system_or_security_rows_by_others(): void
    {
        $actor = $this->auditor(DataScope::School, $this->office->id);

        $hidden = [
            $this->record('role.updated', Role::query()->firstOrFail(), $this->colleague),
            $this->record('office.updated', $this->office, $this->colleague),
            $this->record('security_setting.updated', null, $this->colleague),
            $this->record('migration.backfill'),
            $this->record('authorization.denied', $this->coworker, $this->colleague),
            $this->record('auth.no_access', $this->colleague),
        ];

        $ids = $this->visibleIds($actor);

        foreach ($hidden as $id) {
            $this->assertNotContains($id, $ids);
        }
    }

    public function test_all_scope_sees_every_row(): void
    {
        $actor = $this->auditor(DataScope::All, null);
        $rows = $this->recordSubjectRows();
        $system = $this->record('migration.backfill');
        $role = $this->record('role.updated', Role::query()->firstOrFail(), $this->stranger);

        $ids = $this->visibleIds($actor);

        foreach ([...array_values($rows['in']), ...array_values($rows['out']), $system, $role] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_filters_cannot_reach_rows_outside_scope(): void
    {
        $actor = $this->auditor(DataScope::School, $this->office->id);
        $rows = $this->recordSubjectRows();

        $ids = $this->visibleIds($actor, '&causer_id='.$this->stranger->id.'&subject_type='.urlencode(Personnel::class));

        $this->assertEqualsCanonicalizing([$rows['in']['my personnel'], $rows['in']['personnel']], $ids);
    }

    public function test_options_are_drawn_from_rows_within_reach(): void
    {
        $actor = $this->auditor(DataScope::School, $this->office->id);
        $this->record('personnel.updated', $this->coworker, $this->colleague);
        $this->record('role.updated', Role::query()->firstOrFail(), $this->stranger);
        $this->record('personnel.updated', $this->outsider, $this->stranger);

        $data = $this->actingAs($actor)->getJson('/api/audit-log/options')->assertOk()->json('data');

        $this->assertSame(['personnel.updated'], $data['events']);
        $this->assertSame([['value' => Personnel::class, 'label' => 'Personnel']], $data['subject_types']);
        $this->assertSame([$this->colleague->id], array_column($data['actors'], 'value'));
    }

    public function test_options_for_all_scope_list_every_actor(): void
    {
        $actor = $this->auditor(DataScope::All, null);
        $this->record('personnel.updated', $this->coworker, $this->colleague);
        $this->record('role.updated', Role::query()->firstOrFail(), $this->stranger);

        $data = $this->actingAs($actor)->getJson('/api/audit-log/options')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$this->colleague->id, $this->stranger->id], array_column($data['actors'], 'value'));
        $this->assertContains('role.updated', $data['events']);
    }

    public function test_scoped_actor_without_permission_is_forbidden(): void
    {
        $actor = $this->userWithPermissions([Permission::PersonnelView], ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($actor)->getJson('/api/audit-log')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/audit-log/options')->assertForbidden();
    }
}

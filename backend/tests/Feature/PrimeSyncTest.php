<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use App\Services\PrimeUserSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class PrimeSyncTest extends TestCase
{
    use FakesPrime, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrime();
    }

    private function office(string $name, ?string $type = 'Elementary School', ?int $departmentId = null): Office
    {
        $office = Office::factory()->create(['office_name' => $name, 'office_type' => $type]);
        DB::table('offices')->where('id', $office->id)->update(['prime_department_id' => $departmentId]);

        return $office->fresh();
    }

    private function user(string $email, ?string $employeeNo = null, ?int $officeId = null): User
    {
        $user = User::factory()->create(['email' => $email, 'office_id' => $officeId]);

        if ($employeeNo !== null) {
            DB::table('users')->where('id', $user->id)->update(['employee_no' => $employeeNo]);
        }

        return $user->fresh();
    }

    private function person(string $employeeId, ?int $officeId = null): Personnel
    {
        return Personnel::factory()->create(['employee_id' => $employeeId, 'office_id' => $officeId]);
    }

    /**
     * @return Builder<Activity>
     */
    private function synced(Model $subject): Builder
    {
        return Activity::query()->whereMorphedTo('subject', $subject)->where('description', 'Synced from PRIME');
    }

    private function sync(): void
    {
        $this->artisan('users:sync-external')->assertSuccessful();
    }

    public function test_numbers_are_trimmed_and_placeholders_stored_as_null(): void
    {
        $trimmed = $this->user('trim@deped.gov.ph');
        $this->primeEmployee($this->primeAccount('trim@deped.gov.ph'), ' 007 ');

        $placeholders = [];
        foreach (['N/A', 'na', '-', '--', 'NONE', 'new', '0', '000', '   '] as $i => $value) {
            $placeholders[] = $this->user("p{$i}@deped.gov.ph", $i === 0 ? 'N/A' : null);
            $this->primeEmployee($this->primeAccount("p{$i}@deped.gov.ph"), $value);
        }

        $this->sync();

        $this->assertSame('007', $trimmed->fresh()->employee_no);
        foreach ($placeholders as $user) {
            $this->assertNull($user->fresh()->employee_no);
        }
        $this->assertSame(0, User::where('employee_no', '')->count());
    }

    public function test_a_number_on_two_prime_employees_is_given_to_neither_user(): void
    {
        $a = $this->user('a@deped.gov.ph', '5001');
        $b = $this->user('b@deped.gov.ph');
        $this->primeEmployee($this->primeAccount('a@deped.gov.ph'), '5001');
        $this->primeEmployee($this->primeAccount('b@deped.gov.ph'), '5001 ');

        $this->sync();

        $this->assertNull($a->fresh()->employee_no);
        $this->assertNull($b->fresh()->employee_no);
    }

    public function test_an_account_with_several_numbered_employee_rows_gets_no_number(): void
    {
        $user = $this->user('two@deped.gov.ph', '2001');
        $id = $this->primeAccount('two@deped.gov.ph');
        $this->primeEmployee($id, '2001');
        $this->primeEmployee($id, '2002');

        $this->sync();

        $this->assertNull($user->fresh()->employee_no);
    }

    public function test_numbers_can_swap_between_users_without_a_unique_failure(): void
    {
        $a = $this->user('a@deped.gov.ph', '1111');
        $b = $this->user('b@deped.gov.ph', '2222');
        $c = $this->user('c@deped.gov.ph', '3333');
        $this->primeEmployee($this->primeAccount('a@deped.gov.ph'), '2222');
        $this->primeEmployee($this->primeAccount('b@deped.gov.ph'), '3333');
        $this->primeEmployee($this->primeAccount('c@deped.gov.ph'), '1111');

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Employee numbers: set 0, changed 3, cleared 0.')
            ->assertSuccessful();

        $this->assertSame('2222', $a->fresh()->employee_no);
        $this->assertSame('3333', $b->fresh()->employee_no);
        $this->assertSame('1111', $c->fresh()->employee_no);
    }

    public function test_a_number_held_by_a_local_only_account_is_not_taken(): void
    {
        $local = $this->user('local@deped.gov.ph', '6001');
        $claimant = $this->user('claimant@deped.gov.ph');
        $this->primeEmployee($this->primeAccount('claimant@deped.gov.ph'), '6001');

        $this->sync();

        $this->assertSame('6001', $local->fresh()->employee_no);
        $this->assertNull($claimant->fresh()->employee_no);
    }

    public function test_local_only_accounts_are_untouched(): void
    {
        $office = $this->office('Angeles ES');
        $local = $this->user('local@deped.gov.ph', '9001', $office->id);
        $updatedAt = $local->updated_at;

        $this->sync();

        $local->refresh();
        $this->assertSame('9001', $local->employee_no);
        $this->assertSame($office->id, $local->office_id);
        $this->assertEquals($updatedAt, $local->updated_at);
        $this->assertFalse($this->synced($local)->exists());
    }

    public function test_offices_link_by_name_and_case_only_differences_are_not_renamed(): void
    {
        $office = $this->office('Angeles Elementary School');
        $department = $this->primeDepartment('  ANGELES   elementary school ');

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 1, created 0, renamed 0.')
            ->assertSuccessful();

        $office->refresh();
        $this->assertSame($department, $office->prime_department_id);
        $this->assertSame('Angeles Elementary School', $office->office_name);

        $this->sync();
        $this->assertSame('Angeles Elementary School', $office->fresh()->office_name);
    }

    public function test_a_real_prime_rename_is_applied_to_the_linked_office(): void
    {
        $department = $this->primeDepartment('Lourdes Northwest Elementary School');
        $office = $this->office('Lourdes NW ES', departmentId: $department);

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 0, created 0, renamed 1.')
            ->assertSuccessful();

        $this->assertSame('Lourdes Northwest Elementary School', $office->fresh()->office_name);

        $activity = $this->synced($office)->sole();
        $this->assertSame('Synced from PRIME', $activity->description);
        $this->assertSame('updated', $activity->event);
        $this->assertNull($activity->causer_id);
        $this->assertSame(['office_name' => 'Lourdes Northwest Elementary School'], $activity->attribute_changes['attributes']);
        $this->assertSame(['office_name' => 'Lourdes NW ES'], $activity->attribute_changes['old']);
    }

    public function test_a_rename_onto_a_taken_name_is_skipped_and_reported(): void
    {
        $department = $this->primeDepartment('Taken Name');
        $office = $this->office('Old Name', departmentId: $department);
        $this->office('Taken Name');

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 0, created 0, renamed 0.')
            ->expectsOutputToContain('1 rename(s) skipped')
            ->assertSuccessful();

        $this->assertSame('Old Name', $office->fresh()->office_name);
    }

    public function test_duplicate_department_names_link_to_the_only_staffed_one(): void
    {
        $gueco = $this->office('Gueco Balibago ES');
        $staffed = $this->primeDepartment('Gueco Balibago ES');
        $empty = $this->primeDepartment('Gueco Balibago ES');
        $this->primeEmployee(null, '1', $staffed);

        $tied = $this->office('Tied ES');
        $this->primeEmployee(null, '2', $this->primeDepartment('Tied ES'));
        $this->primeEmployee(null, '3', $this->primeDepartment('Tied ES'));

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 1, created 0, renamed 0.')
            ->expectsOutputToContain('1 ambiguous name(s), 1 duplicate department(s)')
            ->assertSuccessful();

        $this->assertSame($staffed, $gueco->fresh()->prime_department_id);
        $this->assertNull($tied->fresh()->prime_department_id);
        $this->assertFalse(Office::where('prime_department_id', $empty)->exists());

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 0, created 0, renamed 0.')
            ->expectsOutputToContain('1 duplicate department(s), 0 department(s) not created')
            ->assertSuccessful();
        $this->assertSame(2, Office::count());
    }

    public function test_unmatched_departments_create_offices_with_a_mapped_type_only_on_create(): void
    {
        $this->office('Existing ES');
        $this->office('OSDS', 'Division Office');
        $school = $this->primeDepartment('Cutud  Elementary School', 2, 107025);
        $records = $this->primeDepartment('Records Section', 1);
        $hub = $this->primeDepartment('Learning Hub');

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('Offices: linked 0, created 3, renamed 0.')
            ->assertSuccessful();

        $cutud = Office::where('prime_department_id', $school)->sole();
        $this->assertSame('Cutud Elementary School', $cutud->office_name);
        $this->assertSame('Elementary School', $cutud->office_type);
        $this->assertNull($cutud->school_id);
        $this->assertTrue($cutud->is_active);
        $this->assertSame('Division Office', Office::where('prime_department_id', $records)->sole()->office_type);
        $this->assertNull(Office::where('prime_department_id', $hub)->sole()->office_type);

        $created = $this->synced($cutud)->sole();
        $this->assertSame('created', $created->event);
        $this->assertSame('Synced from PRIME', $created->description);
        $this->assertSame($school, $created->attribute_changes['attributes']['prime_department_id']);

        $cutud->update(['office_type' => 'Integrated School']);
        $this->prime('departments')->where('dept_id', $school)->update(['office_id' => 1]);

        $this->sync();

        $this->assertSame('Integrated School', $cutud->fresh()->office_type);
        $this->assertSame(5, Office::count());
    }

    public function test_users_get_the_office_linked_to_their_prime_department(): void
    {
        $angelesDept = $this->primeDepartment('Angeles ES');
        $angeles = $this->office('Angeles ES', departmentId: $angelesDept);
        $irisOnly = $this->office('ICT Unit', 'Unit');
        $newDept = $this->primeDepartment('Brand New ES');

        $moved = $this->user('moved@deped.gov.ph', officeId: $irisOnly->id);
        $this->primeEmployee($this->primeAccount('moved@deped.gov.ph'), '1', $angelesDept);

        $noEmployee = $this->user('noemployee@deped.gov.ph');
        $this->primeAccount('noemployee@deped.gov.ph', $newDept);

        $cleared = $this->user('cleared@deped.gov.ph', officeId: $angeles->id);
        $this->primeEmployee($this->primeAccount('cleared@deped.gov.ph'), '3', 555);

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('User offices: set 1, changed 1, cleared 1.')
            ->assertSuccessful();

        $this->assertSame($angeles->id, $moved->fresh()->office_id);
        $this->assertSame(Office::where('prime_department_id', $newDept)->value('id'), $noEmployee->fresh()->office_id);
        $this->assertNull($cleared->fresh()->office_id);

        $activity = $this->synced($moved)->latest('id')->firstOrFail();
        $this->assertNull($activity->causer_id);
        $this->assertSame(['office_id' => $angeles->id, 'employee_no' => '1'], $activity->attribute_changes['attributes']);
        $this->assertSame(['office_id' => $irisOnly->id, 'employee_no' => null], $activity->attribute_changes['old']);
    }

    public function test_a_department_removed_from_prime_keeps_its_office_but_users_lose_it(): void
    {
        $gone = $this->office('Closed School', departmentId: 999);
        $user = $this->user('a@deped.gov.ph', officeId: $gone->id);
        $this->primeEmployee($this->primeAccount('a@deped.gov.ph'), '1', 999);

        $this->artisan('users:sync-external')
            ->expectsOutputToContain('1 office(s) whose department left PRIME')
            ->assertSuccessful();

        $gone->refresh();
        $this->assertTrue($gone->exists);
        $this->assertTrue($gone->is_active);
        $this->assertSame(999, $gone->prime_department_id);
        $this->assertNull($user->fresh()->office_id);
    }

    public function test_unreachable_prime_leaves_iris_unchanged(): void
    {
        $office = $this->office('Angeles ES');
        $user = $this->user('a@deped.gov.ph', '1', $office->id);
        $before = [DB::table('users')->get()->toJson(), DB::table('offices')->get()->toJson(), DB::table('activity_log')->count()];

        Schema::connection('external_users')->drop('employees');

        $this->artisan('users:sync-external')->expectsOutputToContain('PRIME sync failed')->assertFailed();
        $this->assertSame($before, [DB::table('users')->get()->toJson(), DB::table('offices')->get()->toJson(), DB::table('activity_log')->count()]);

        $this->breakPrime();
        app(PrimeUserSync::class)->syncUser($user);
        $this->assertSame($before, [DB::table('users')->get()->toJson(), DB::table('offices')->get()->toJson(), DB::table('activity_log')->count()]);
    }

    public function test_session_load_syncs_only_that_users_number_and_office(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $dept = $this->primeDepartment('Angeles ES');
        $office = $this->office('Angeles ES', departmentId: $dept);
        $unlinkedDept = $this->primeDepartment('Not Yet ES');

        $user = $this->user('juan@deped.gov.ph')->givePermissionTo(Permission::EquipmentView->value);
        $this->primeEmployee($this->primeAccount('juan@deped.gov.ph', name: 'Juan Dela Cruz'), ' 4321 ', $dept);

        $other = $this->user('other@deped.gov.ph', officeId: $office->id)->givePermissionTo(Permission::EquipmentView->value);
        $this->primeEmployee($this->primeAccount('other@deped.gov.ph'), '8888', $unlinkedDept);

        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.name', 'Juan Dela Cruz');

        $user->refresh();
        $this->assertSame('4321', $user->employee_no);
        $this->assertSame($office->id, $user->office_id);
        $this->assertSame($office->id, $other->fresh()->office_id);
        $this->assertNull($other->fresh()->employee_no);
        $this->assertSame(1, Office::count());

        $activity = $this->synced($user)->sole();
        $this->assertSame('Synced from PRIME', $activity->description);
        $this->assertSame('4321', $activity->attribute_changes['attributes']['employee_no']);

        $this->actingAs($other)->getJson('/api/user')->assertOk();
        $this->assertSame('8888', $other->fresh()->employee_no);
        $this->assertNull($other->fresh()->office_id);
    }

    public function test_session_load_does_not_take_a_number_held_by_another_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $holder = $this->user('holder@deped.gov.ph', '7001');
        $user = $this->user('juan@deped.gov.ph', '7002')->givePermissionTo(Permission::EquipmentView->value);
        $this->primeEmployee($this->primeAccount('juan@deped.gov.ph'), '7001');

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $this->assertNull($user->fresh()->employee_no);
        $this->assertSame('7001', $holder->fresh()->employee_no);
    }

    public function test_fill_office_command_fills_only_empty_offices_and_audits(): void
    {
        $dept = $this->primeDepartment('Angeles ES');
        $angeles = $this->office('Angeles ES', departmentId: $dept);
        $other = $this->office('Other ES');
        $unlinked = $this->primeDepartment('Unlinked ES');

        $this->primeEmployee(null, '1001', $dept);
        $this->primeEmployee(null, '1002', $dept);
        $this->primeEmployee(null, '1003', $unlinked);
        $this->primeEmployee(null, '1004', $dept);
        $this->primeEmployee(null, '1004', $dept);
        $this->primeEmployee(null, '1005');

        $filled = $this->person(' 1001');
        $kept = $this->person('1002', $other->id);
        $noOffice = $this->person('1003');
        $ambiguous = $this->person('1004');
        $noDepartment = $this->person('1005');
        $placeholder = $this->person('N/A');
        $unmatched = $this->person('9999');
        $trashed = $this->person('1001');
        $trashed->delete();

        $this->artisan('personnel:fill-office-from-prime')
            ->expectsOutputToContain('1 personnel with no office can be filled from PRIME.')
            ->expectsOutputToContain('5 personnel with no office are skipped.')
            ->expectsConfirmation('Fill the office of 1 personnel?', 'no')
            ->assertSuccessful();
        $this->assertNull($filled->fresh()->office_id);

        $this->artisan('personnel:fill-office-from-prime', ['--force' => true])
            ->expectsTable(['Reason', 'Personnel'], [
                ['no office linked to the PRIME department', 1],
                ['number is on several PRIME employees', 1],
                ['PRIME employee has no department', 1],
                ['no employee number', 1],
                ['no PRIME employee with this number', 1],
            ])
            ->expectsOutputToContain('Filled 1 personnel office(s).')
            ->assertSuccessful();

        $this->assertSame($angeles->id, $filled->fresh()->office_id);
        $this->assertSame($other->id, $kept->fresh()->office_id);
        foreach ([$noOffice, $ambiguous, $noDepartment, $placeholder, $unmatched] as $person) {
            $this->assertNull($person->fresh()->office_id);
        }
        $this->assertNull(Personnel::withTrashed()->find($trashed->id)->office_id);

        $activity = $this->synced($filled)->sole();
        $this->assertNull($activity->causer_id);
        $this->assertSame(['office_id' => $angeles->id], $activity->attribute_changes['attributes']);
        $this->assertSame(['office_id' => null], $activity->attribute_changes['old']);

        $this->artisan('personnel:fill-office-from-prime', ['--force' => true])
            ->expectsOutputToContain('0 personnel with no office can be filled from PRIME.')
            ->assertSuccessful();
    }

    public function test_fill_office_command_fails_cleanly_when_prime_is_unreachable(): void
    {
        $person = $this->person('1001');
        $this->breakPrime();

        $this->artisan('personnel:fill-office-from-prime', ['--force' => true])
            ->expectsOutputToContain('Could not read PRIME or IRIS')
            ->assertFailed();

        $this->assertNull($person->fresh()->office_id);
    }

    public function test_preview_after_a_sync_still_writes_nothing(): void
    {
        $this->office('Angeles ES');
        $this->primeEmployee($this->primeAccount('a@deped.gov.ph', $this->primeDepartment('Angeles ES')), '1');
        $this->primeDepartment('New ES');
        $this->sync();

        $before = [DB::table('users')->get()->toJson(), DB::table('offices')->get()->toJson(), DB::table('activity_log')->count()];
        $this->assertSame(0, Artisan::call('prime:sync-preview'));
        $this->assertSame($before, [DB::table('users')->get()->toJson(), DB::table('offices')->get()->toJson(), DB::table('activity_log')->count()]);
    }
}

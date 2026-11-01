<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class PrimePersonnelSyncTest extends TestCase
{
    use CreatesUsersWithRoles, FakesPrime, RefreshDatabase;

    private Office $office;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();
        $this->department = $this->primeDepartment('Sample ES');
        $this->office = Office::factory()->create(['office_name' => 'Sample ES']);
        $this->office->forceFill(['prime_department_id' => $this->department])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employee(string $number, array $overrides = []): int
    {
        $id = $this->primeEmployee(null, $number, $this->department, $overrides['surname'] ?? 'Reyes');
        unset($overrides['surname']);

        if ($overrides !== []) {
            $this->prime('employees')->where('employee_id', $id)->update($overrides);
        }

        return $id;
    }

    private function position(string $title): int
    {
        return $this->prime('personnelposition')->insertGetId(['type_of_position' => 'Teaching', 'position_title' => $title]);
    }

    private function sync(string ...$options): void
    {
        $this->artisan('personnel:sync-prime', array_fill_keys($options, true) + ['--force' => true])->assertSuccessful();
    }

    public function test_creates_personnel_from_prime_with_position_office_and_employment_fields(): void
    {
        $this->employee('4127480', [
            'surname' => 'Santos', 'middle_name' => 'Cruz', 'personnel_position_id' => (string) $this->position('Teacher III'),
            'date_original_appointment' => '2015-02-16', 'appointment_status' => 'Permanent', 'curr_psipop_item_number' => 'T3-123',
        ]);

        $this->sync();

        $person = Personnel::query()->where('employee_id', '4127480')->sole();
        $this->assertSame('Santos', $person->last_name);
        $this->assertSame('Cruz', $person->middle_name);
        $this->assertSame($this->office->id, $person->office_id);
        $this->assertSame('Teacher III', $person->personnelPosition?->name);
        $this->assertSame('2015-02-16', $person->date_hired?->toDateString());
        $this->assertSame('Permanent', $person->appointment_status);
        $this->assertSame('T3-123', $person->plantilla_item_no);
        $this->assertTrue($person->isManagedByPrime());
        $this->assertFalse($person->inactive);
        $this->assertDatabaseHas('activity_log', ['event' => 'personnel.prime_synced']);
    }

    public function test_takes_over_a_legacy_record_by_employee_number_keeping_its_id_equipment_and_iris_fields(): void
    {
        $legacy = Personnel::factory()->create(['employee_id' => '3161318', 'last_name' => 'Old', 'mobile_2' => '09170000000', 'oic' => true, 'office_id' => null]);
        $equipment = Equipment::factory()->create(['current_accountable_officer_id' => $legacy->id, 'current_end_user_id' => null]);
        $this->employee('3161318', ['surname' => 'Garcia']);

        $this->sync();

        $legacy->refresh();
        $this->assertSame('Garcia', $legacy->last_name);
        $this->assertSame($this->office->id, $legacy->office_id);
        $this->assertSame('09170000000', $legacy->mobile_2);
        $this->assertTrue($legacy->oic);
        $this->assertTrue($legacy->isManagedByPrime());
        $this->assertSame($legacy->id, $equipment->fresh()->current_accountable_officer_id);
        $this->assertSame(1, Personnel::query()->count());
    }

    public function test_maps_inactive_statuses_and_skips_applicants_blank_and_shared_numbers(): void
    {
        $retiredUser = 77;
        $this->employee('1001', ['employee_status' => 'Inactive - Retired', 'user_id' => $retiredUser]);
        $this->prime('service_records')->insert(['user_id' => $retiredUser, 'separation_date' => '2025-06-30', 'separation_cause' => null]);
        $this->employee('1002', ['employee_status' => 'Inactive - Applicant']);
        $this->primeEmployee(null, null, $this->department);
        $this->employee('1003');
        $this->employee('1003 ');

        $this->sync();

        $retired = Personnel::query()->where('employee_id', '1001')->sole();
        $this->assertTrue($retired->inactive);
        $this->assertSame('Retired', $retired->separation_cause);
        $this->assertSame('2025-06-30', $retired->separation_date?->toDateString());
        $this->assertSame(1, Personnel::query()->count());
    }

    public function test_deactivates_synced_personnel_no_longer_in_prime(): void
    {
        $id = $this->employee('2001');
        $this->sync();
        $this->prime('employees')->where('employee_id', $id)->delete();

        $this->sync();

        $person = Personnel::query()->where('employee_id', '2001')->sole();
        $this->assertTrue($person->inactive);
        $this->assertSame('No longer in PRIME', $person->separation_cause);
    }

    public function test_removes_unreferenced_legacy_records_only_with_the_flag_and_never_referenced_ones(): void
    {
        $free = Personnel::factory()->create(['employee_id' => 'LEGACY-1']);
        $referenced = Personnel::factory()->create(['employee_id' => 'LEGACY-2']);
        Equipment::factory()->create(['current_accountable_officer_id' => $referenced->id, 'current_end_user_id' => null]);

        $this->sync();
        $this->assertNotSoftDeleted($free);

        $this->sync('--remove-legacy');
        $this->assertSoftDeleted($free);
        $this->assertNotSoftDeleted($referenced);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->employee('3001');

        $this->artisan('personnel:sync-prime', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Personnel::query()->count());
        $this->assertSame(0, Position::query()->count());
    }

    public function test_prime_fields_are_locked_on_edit_and_prime_records_cannot_be_deleted(): void
    {
        $this->employee('5001', ['surname' => 'Lopez']);
        $this->sync();
        $person = Personnel::query()->where('employee_id', '5001')->sole();
        $user = $this->userWithPermissions([Permission::PersonnelView, Permission::PersonnelEdit, Permission::PersonnelDelete]);

        $this->actingAs($user)->putJson("/api/personnel/{$person->id}", [
            'employee_id' => '5001', 'last_name' => 'Changed', 'first_name' => 'Maria', 'mobile_2' => '09181111111', 'inactive' => false,
        ])->assertOk();

        $person->refresh();
        $this->assertSame('Lopez', $person->last_name);
        $this->assertSame('09181111111', $person->mobile_2);

        $this->actingAs($user)->deleteJson("/api/personnel/{$person->id}")->assertForbidden();
        $this->assertNotSoftDeleted($person);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class PreviewPrimeSyncTest extends TestCase
{
    use FakesPrime, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrime();
    }

    private function office(string $name, string $type = 'Elementary School'): int
    {
        return DB::table('offices')->insertGetId(['office_name' => $name, 'office_type' => $type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function person(string $employeeId, ?int $officeId = null): int
    {
        return DB::table('personnel')->insertGetId([
            'employee_id' => $employeeId, 'last_name' => 'Cruz', 'first_name' => 'Ana', 'office_id' => $officeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach ([config('database.default'), 'external_users'] as $connection) {
            foreach (Schema::connection($connection)->getTableListing(schemaQualified: false) as $table) {
                $snapshot["{$connection}.{$table}"] = md5((string) json_encode(DB::connection($connection)->table($table)->get()));
            }
        }

        return $snapshot;
    }

    private function preview(?string $section = null): string
    {
        $this->assertSame(0, Artisan::call('prime:sync-preview', $section === null ? [] : ['--section' => $section]));

        return Artisan::output();
    }

    /**
     * @param  list<string|int>  $cells
     */
    private function assertRow(string $output, array $cells): void
    {
        $pattern = '/\|\s*'.implode('\s*\|\s*', array_map(fn ($cell) => preg_quote((string) $cell, '/'), $cells)).'\s*\|/';

        $this->assertMatchesRegularExpression($pattern, $output);
    }

    public function test_users_section_reports_numbers_gained_lost_and_ambiguous(): void
    {
        $gets = User::factory()->create(['email' => 'gets@deped.gov.ph']);
        $this->primeEmployee($this->primeAccount('GETS@deped.gov.ph'), ' 007 ');

        $twoRows = User::factory()->create(['email' => 'two@deped.gov.ph']);
        $twoId = $this->primeAccount('two@deped.gov.ph');
        $this->primeEmployee($twoId, '2001');
        $this->primeEmployee($twoId, '2002');

        $shared = User::factory()->create(['email' => 'shared@deped.gov.ph']);
        $sharedId = $this->primeAccount('shared@deped.gov.ph');
        $this->primeEmployee($sharedId, '3001');
        $this->primeEmployee(null, '3001 ');

        $loses = User::factory()->withEmployeeNo('4001')->create(['email' => 'loses@deped.gov.ph']);
        $this->primeAccount('loses@deped.gov.ph');

        $dupe = User::factory()->create(['email' => 'dupe@deped.gov.ph']);
        $this->primeEmployee($this->primeAccount('dupe@deped.gov.ph'), '5001');
        $this->primeAccount('Dupe@deped.gov.ph');

        User::factory()->create(['email' => 'local@deped.gov.ph']);

        $before = $this->snapshot();
        $output = $this->preview('users');

        $this->assertStringContainsString('users.employee_no: present', $output);
        $this->assertStringContainsString('2 user(s) would get, change or lose an employee number:', $output);
        $this->assertRow($output, [$gets->id, $gets->name, 'gets@deped.gov.ph', '-', '007', 'gets']);
        $this->assertRow($output, [$loses->id, $loses->name, 'loses@deped.gov.ph', '4001', '-', 'loses', 'no employee row']);

        $this->assertStringContainsString('3 user(s) left without a number because PRIME is ambiguous:', $output);
        $this->assertRow($output, [$twoRows->id, $twoRows->name, 'two@deped.gov.ph', $twoId, '2 numbered employee rows (employee_id 2, 3)']);
        $this->assertRow($output, [$shared->id, $shared->name, 'shared@deped.gov.ph', $sharedId, 'deped_id_no 3001 is on 2 employee rows (employee_id 4, 5)']);
        $this->assertRow($output, [$dupe->id, $dupe->name, 'dupe@deped.gov.ph', '-', '2 PRIME accounts share this email (user_id 5, 6)']);

        $this->assertStringContainsString('1 user(s) resolve to a number; 1 user(s) have no PRIME account and are not touched.', $output);
        $this->assertStringNotContainsString('local@deped.gov.ph', $output);
        $this->assertStringNotContainsString('== (b)', $output);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_users_section_rejects_a_number_already_held_by_an_untouched_user(): void
    {
        $holder = User::factory()->create(['email' => 'holder@deped.gov.ph']);
        DB::table('users')->where('id', $holder->id)->update(['employee_no' => '6001']);

        $claimant = User::factory()->create(['email' => 'claimant@deped.gov.ph']);
        $claimantPrimeId = $this->primeAccount('claimant@deped.gov.ph');
        $this->primeEmployee($claimantPrimeId, '6001');

        $before = $this->snapshot();
        $output = $this->preview('users');

        $this->assertStringContainsString('users.employee_no: present', $output);
        $this->assertStringContainsString('0 user(s) would get, change or lose an employee number:', $output);
        $this->assertRow($output, [$claimant->id, $claimant->name, 'claimant@deped.gov.ph', $claimantPrimeId, "employee number 6001 also resolves to IRIS user {$holder->id}"]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_users_section_reports_a_changed_number_without_stripping_zeros(): void
    {
        $user = User::factory()->create(['email' => 'ana@deped.gov.ph']);
        DB::table('users')->where('id', $user->id)->update(['employee_no' => '100']);
        $this->primeEmployee($this->primeAccount('ana@deped.gov.ph'), '0100');

        $this->assertRow($this->preview('users'), [$user->id, $user->name, 'ana@deped.gov.ph', '100', '0100', 'changes']);
    }

    public function test_personnel_section_lists_matches_side_by_side_and_unmatched(): void
    {
        $school = $this->primeDepartment('Angeles ES');
        $this->primeEmployee($this->primeAccount('maria@deped.gov.ph'), '1001', $school, 'Santos');
        $noAccount = $this->primeEmployee(null, '1002');
        $first = $this->primeEmployee(null, '1003');
        $second = $this->primeEmployee(null, '1003');

        $this->person(' 1001');
        $this->person('1002');
        $ambiguous = $this->person('1003');
        $unmatched = $this->person('9999');

        $before = $this->snapshot();
        $output = $this->preview('personnel');

        $this->assertStringContainsString('2 personnel matched to exactly one PRIME employee:', $output);
        $this->assertRow($output, ['1001', 'Cruz, Ana', 'Santos, Maria', 'maria@deped.gov.ph', "Angeles ES (#{$school})"]);
        $this->assertRow($output, ['1002', 'Cruz, Ana', 'Reyes, Maria', "emp{$noAccount}@deped.gov.ph (no PRIME account)", '-']);
        $this->assertStringContainsString('1 personnel whose number is on more than one PRIME employee row:', $output);
        $this->assertRow($output, [$ambiguous, '1003', 'Cruz, Ana', "{$first}, {$second}"]);
        $this->assertStringContainsString('1 personnel with no PRIME match:', $output);
        $this->assertRow($output, [$unmatched, '9999', 'Cruz, Ana']);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_offices_section_for_unlinked_offices_proposes_links_creations_and_collisions(): void
    {
        $angeles = $this->office('Angeles ES');
        $twin = $this->office('Twin ES');
        $taken = $this->office('Taken Name');
        $unit = $this->office('ICT Unit', 'Unit');
        $this->office('OSDS', 'Division Office');

        $angelesDept = $this->primeDepartment('  ANGELES es ');
        $twinA = $this->primeDepartment('Twin ES');
        $twinB = $this->primeDepartment('twin es');
        $cutud = $this->primeDepartment('Cutud ES', 2, 107025);
        $records = $this->primeDepartment('Records Section', 1);
        $dupA = $this->primeDepartment('Dup HS');
        $this->primeDepartment('dup hs');
        $takenDept = $this->primeDepartment('taken name', 1);
        $unknown = $this->primeDepartment('Learning Hub');

        $before = $this->snapshot();
        $output = $this->preview('offices');

        $this->assertStringContainsString('2 office(s) would be linked to a PRIME department by name:', $output);
        $this->assertRow($output, [$angeles, 'Angeles ES', $angelesDept, 'ANGELES es']);
        $this->assertRow($output, [$taken, 'Taken Name', $takenDept, 'taken name']);

        $this->assertStringContainsString('3 office(s) would be created for unmatched PRIME departments:', $output);
        $this->assertRow($output, [$cutud, 'Cutud ES', 2, 'Elementary School', 107025]);
        $this->assertRow($output, [$records, 'Records Section', 1, 'Division Office', '-']);
        $this->assertRow($output, [$unknown, 'Learning Hub', 2, '(none, set by hand)', '-']);

        $this->assertStringContainsString('2 unmatched PRIME department(s) that cannot become an office:', $output);
        $this->assertRow($output, [$dupA, 'Dup HS', 'name shared by 2 unmatched PRIME departments']);

        $this->assertStringContainsString('0 linked office(s) would be renamed to the PRIME name:', $output);

        $this->assertStringContainsString('1 ambiguous name(s), left unlinked:', $output);
        $this->assertRow($output, ['twin es', $twin, "{$twinA}, {$twinB}", '0, 0']);

        $this->assertStringContainsString('0 linked office(s) whose PRIME department no longer exists:', $output);
        $this->assertStringContainsString('3 IRIS-only office(s) with no PRIME department:', $output);
        $this->assertRow($output, [$unit, 'ICT Unit', 'Unit', 'yes']);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_offices_section_reports_a_department_named_like_a_linked_twin_as_a_leftover(): void
    {
        $linked = $this->office('Claimed ES');
        $claimedDept = $this->primeDepartment('Claimed ES');
        DB::table('offices')->where('id', $linked)->update(['prime_department_id' => $claimedDept]);
        $duplicate = $this->primeDepartment('Claimed ES');

        $output = $this->preview('offices');

        $this->assertStringContainsString('0 office(s) would be created for unmatched PRIME departments:', $output);
        $this->assertRow($output, [$duplicate, 'Claimed ES', 0, $claimedDept, "same name as department #{$claimedDept}, linked to office #{$linked}"]);
    }

    public function test_offices_section_blocks_creating_an_office_whose_name_is_taken(): void
    {
        $taken = $this->office('Claimed ES');
        $other = $this->primeDepartment('Other ES');
        DB::table('offices')->where('id', $taken)->update(['prime_department_id' => $other]);
        $duplicate = $this->primeDepartment('Claimed ES');

        $this->assertRow($this->preview('offices'), [$duplicate, 'Claimed ES', "name already used by office #{$taken}"]);
    }

    public function test_offices_section_links_the_only_staffed_department_of_a_shared_name(): void
    {
        $gueco = $this->office('Gueco Balibago ES');
        $staffed = $this->primeDepartment('GUECO BALIBAGO ES');
        $empty = $this->primeDepartment('Gueco  Balibago ES');
        $this->primeEmployee(null, '1', $staffed);
        $this->primeEmployee(null, '2', $staffed);

        $tied = $this->office('Tied ES');
        $tiedA = $this->primeDepartment('Tied ES');
        $tiedB = $this->primeDepartment('Tied ES');
        $this->primeEmployee(null, '3', $tiedA);
        $this->primeEmployee(null, '4', $tiedB);

        $output = $this->preview('offices');

        $this->assertStringContainsString('1 office(s) would be linked to a PRIME department by name:', $output);
        $this->assertRow($output, [$gueco, 'Gueco Balibago ES', $staffed, 'GUECO BALIBAGO ES']);
        $this->assertStringContainsString('0 linked office(s) would be renamed to the PRIME name:', $output);
        $this->assertRow($output, [$empty, 'Gueco Balibago ES', 0, $staffed, "same name as department #{$staffed}, which has 2 employee(s)"]);
        $this->assertRow($output, ['tied es', $tied, "{$tiedA}, {$tiedB}", '1, 1']);
        $this->assertStringContainsString('0 office(s) would be created for unmatched PRIME departments:', $output);
    }

    public function test_users_section_treats_placeholder_numbers_as_no_number(): void
    {
        foreach (['N/A', ' na ', '-', '--', 'None', 'NEW', '0', '0000', ''] as $i => $placeholder) {
            User::factory()->create(['email' => "p{$i}@deped.gov.ph"]);
            $this->primeEmployee($this->primeAccount("p{$i}@deped.gov.ph"), $placeholder);
        }
        $held = User::factory()->withEmployeeNo('N/A')->create(['email' => 'held@deped.gov.ph']);
        $this->primeEmployee($this->primeAccount('held@deped.gov.ph'), 'N/A');

        $output = $this->preview('users');

        $this->assertStringContainsString('1 user(s) would get, change or lose an employee number:', $output);
        $this->assertRow($output, [$held->id, $held->name, 'held@deped.gov.ph', 'N/A', '-', 'loses', 'placeholder']);
        $this->assertStringContainsString('0 user(s) left without a number because PRIME is ambiguous:', $output);
    }

    public function test_offices_section_with_link_column_reports_renames_collisions_and_gone_departments(): void
    {
        $renamed = $this->primeDepartment('Lourdes Northwest Elementary School');
        $colliding = $this->primeDepartment('Taken Name');

        $linked = $this->office('Lourdes NW ES');
        $blocked = $this->office('Old Name');
        $taken = $this->office('Taken Name');
        $gone = $this->office('Closed School');
        DB::table('offices')->where('id', $linked)->update(['prime_department_id' => $renamed]);
        DB::table('offices')->where('id', $blocked)->update(['prime_department_id' => $colliding]);
        DB::table('offices')->where('id', $gone)->update(['prime_department_id' => 999]);

        $before = $this->snapshot();
        $output = $this->preview('offices');

        $this->assertStringContainsString('offices.prime_department_id: present', $output);
        $this->assertStringContainsString('0 office(s) would be linked to a PRIME department by name:', $output);
        $this->assertRow($output, [$linked, 'Lourdes NW ES', 'Lourdes Northwest Elementary School', $renamed]);
        $this->assertStringContainsString('1 rename(s) skipped because office_name must be unique:', $output);
        $this->assertRow($output, [$blocked, 'Old Name', 'Taken Name', $colliding, "name already used by office #{$taken}"]);
        $this->assertRow($output, [$gone, 'Closed School', 999]);
        $this->assertStringContainsString('1 IRIS-only office(s) with no PRIME department:', $output);
        $this->assertRow($output, [$taken, 'Taken Name', 'Elementary School', 'yes']);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_user_offices_section_reports_changed_set_and_cleared_offices(): void
    {
        $angeles = $this->office('Angeles ES');
        $cutud = $this->office('Cutud ES');
        $angelesDept = $this->primeDepartment('Angeles ES');
        $newDept = $this->primeDepartment('Brand New ES');
        $this->primeDepartment('Cutud ES');

        User::factory()->create(['email' => 'stay@deped.gov.ph', 'office_id' => $angeles]);
        $this->primeEmployee($this->primeAccount('stay@deped.gov.ph'), '1', $angelesDept);

        $moved = User::factory()->create(['email' => 'moved@deped.gov.ph', 'office_id' => $cutud]);
        $this->primeEmployee($this->primeAccount('moved@deped.gov.ph'), '2', $angelesDept);

        $set = User::factory()->create(['email' => 'noemployee@deped.gov.ph', 'office_id' => null]);
        $this->primeAccount('noemployee@deped.gov.ph', $newDept);

        $cleared = User::factory()->create(['email' => 'cleared@deped.gov.ph', 'office_id' => $cutud]);
        $this->primeEmployee($this->primeAccount('cleared@deped.gov.ph'), '3', 555);

        User::factory()->create(['email' => 'local@deped.gov.ph', 'office_id' => $cutud]);

        $before = $this->snapshot();
        $output = $this->preview('user-offices');

        $this->assertStringContainsString('3 user(s) whose office would change or be cleared:', $output);
        $this->assertRow($output, [$moved->id, $moved->name, 'moved@deped.gov.ph', "Cutud ES (#{$cutud})", "Angeles ES (#{$angelesDept})", "Angeles ES (#{$angeles})", 'changed']);
        $this->assertRow($output, [$set->id, $set->name, 'noemployee@deped.gov.ph', '-', "Brand New ES (#{$newDept})", 'new office: Brand New ES', 'set']);
        $this->assertRow($output, [$cleared->id, $cleared->name, 'cleared@deped.gov.ph', "Cutud ES (#{$cutud})", '#555 (not in PRIME)', '-', 'cleared']);
        $this->assertStringNotContainsString('stay@deped.gov.ph', $output);
        $this->assertStringNotContainsString('local@deped.gov.ph', $output);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_personnel_offices_section_only_fills_empty_offices(): void
    {
        $angeles = $this->office('Angeles ES');
        $dept = $this->primeDepartment('Angeles ES');
        $this->primeEmployee(null, '1001', $dept);
        $this->primeEmployee(null, '1002', $dept);
        $this->primeEmployee(null, '1003', 777);

        $filled = $this->person('1001');
        $this->person('1002', $this->office('Other ES'));
        $this->person('1003');
        $this->person('1004');

        $before = $this->snapshot();
        $output = $this->preview('personnel-offices');

        $this->assertStringContainsString('1 personnel with no office would get one:', $output);
        $this->assertRow($output, [$filled, '1001', 'Cruz, Ana', "Angeles ES (#{$dept})", "Angeles ES (#{$angeles})"]);
        $this->assertStringContainsString('2 personnel with no office stay empty', $output);
        $this->assertStringNotContainsString('1002', $output);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_full_preview_prints_every_section_and_writes_nothing(): void
    {
        $this->office('Angeles ES');
        $this->primeEmployee($this->primeAccount('a@deped.gov.ph', $this->primeDepartment('Angeles ES')), '1');
        User::factory()->create(['email' => 'a@deped.gov.ph']);
        $this->person('1');

        $before = $this->snapshot();
        $activity = DB::table('activity_log')->count();
        $output = $this->preview();

        foreach (['(a)', '(b)', '(c)', '(d)', '(e)'] as $section) {
            $this->assertStringContainsString("== {$section}", $output);
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($activity, DB::table('activity_log')->count());
    }

    public function test_unknown_section_fails(): void
    {
        $this->artisan('prime:sync-preview', ['--section' => 'everything'])
            ->expectsOutputToContain('Unknown section')
            ->assertFailed();
    }

    public function test_unreachable_prime_fails_without_writing(): void
    {
        Schema::connection('external_users')->drop('employees');
        User::factory()->create();
        $before = $this->snapshot();

        $this->artisan('prime:sync-preview', ['--section' => 'users'])
            ->expectsOutputToContain('Could not read PRIME or IRIS')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }
}

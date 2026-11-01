<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Position;
use App\Models\User;
use App\Services\PsfInventoryImport;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class ImportPsfInventoryTest extends TestCase
{
    use FakesPrime, RefreshDatabase;

    private string $folder;

    private Office $school;

    private Office $otherSchool;

    private Personnel $head;

    private Personnel $teacher;

    private Personnel $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();
        Storage::fake('local');

        $this->school = Office::factory()->create(['office_name' => 'Cutud ES', 'school_id' => '107025', 'prime_department_id' => $this->primeDepartment('Cutud ES')]);
        $this->otherSchool = Office::factory()->create(['office_name' => 'Air Force City ES', 'school_id' => '107034', 'prime_department_id' => $this->primeDepartment('Air Force City ES')]);
        Office::factory()->create(['office_name' => 'Unlinked ES', 'school_id' => '107099', 'prime_department_id' => null]);

        $principal = Position::query()->firstOrCreate(['name' => 'School Principal I']);
        $this->head = Personnel::factory()->create(['office_id' => $this->school->id, 'employee_id' => '4400001', 'position_id' => $principal->id, 'inactive' => false]);
        $this->teacher = Personnel::factory()->create(['office_id' => $this->school->id, 'employee_id' => '4400002']);
        $this->outsider = Personnel::factory()->create(['office_id' => $this->otherSchool->id, 'employee_id' => '4400003']);

        $this->folder = storage_path('framework/testing/psf-'.uniqid());
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $rows  column letter => value
     */
    private function workbook(string $relativePath, array $rows): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Equipment');
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue($column.(6 + $i), $value);
            }
        }
        File::ensureDirectoryExists(dirname($this->folder.'/'.$relativePath));
        (new Xlsx($spreadsheet))->save($this->folder.'/'.$relativePath);
    }

    private function importer(): User
    {
        return User::factory()->create(['email' => 'importer@deped.gov.ph']);
    }

    public function test_rows_land_only_on_personnel_of_the_files_own_school(): void
    {
        $this->workbook('107025 - Cutud ES/2026_107025-Cutud ES_ict-equipment-inventory-school-template.xlsx', [
            ['B' => 'CUT-001', 'E' => 'Laptop', 'F' => 'Piece', 'G' => 'A4TECH', 'W' => 'Program Support Funds (PSF)', 'AC' => "{$this->teacher->full_name} - 4400002", 'AP' => 'Serviceable'],
            ['B' => 'CUT-001', 'E' => 'RL-MOnitor', 'G' => 'Dell', 'AC' => "{$this->outsider->full_name} - 4400003", 'AP' => 'Some units unservicable'],
            ['B' => 'N/A', 'E' => 'Aircondition', 'G' => 'Dell'],
            ['E' => 'Laptop', 'H' => str_repeat('Acer TravelMate full spec ', 20), 'D' => str_repeat('S', 300)],
        ]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true])->assertSuccessful();

        $imported = Equipment::query()->with('itemType', 'brand')->orderBy('id')->get();
        $this->assertCount(4, $imported);

        [$laptop, $monitor, $aircon, $unbranded] = $imported;
        $this->assertSame('Others', $unbranded->brand->name);
        $this->assertSame(255, mb_strlen((string) $unbranded->model));
        $this->assertSame(255, mb_strlen((string) $unbranded->serial_number));
        $this->assertStringContainsString(trim(str_repeat('Acer TravelMate full spec ', 20)), (string) $unbranded->specifications);
        $this->assertSame($this->teacher->id, $laptop->current_accountable_officer_id);
        $this->assertSame('CUT-001', $laptop->old_property_no);
        $this->assertSame('Others', $laptop->brand->name);
        $this->assertSame('A4TECH', $laptop->brand_other);
        $this->assertSame('Program Support Fund (PSF)', $laptop->source_fund);
        $this->assertMatchesRegularExpression('/^AC-\d{4}-IT-[A-Z0-9]{3}-\d{6}$/', (string) $laptop->property_no);

        $this->assertSame('Monitor', $monitor->itemType->name);
        $this->assertSame($this->head->id, $monitor->current_accountable_officer_id, 'an officer from another school must not receive the item');
        $this->assertSame('Some units unservicable', $monitor->equipment_condition_other);

        $this->assertNull($aircon->old_property_no);
        $this->assertSame('Aircondition', $aircon->item_type_other);
        $this->assertSame($this->head->id, $aircon->current_accountable_officer_id);

        $this->assertSame(0, Equipment::query()->where('current_accountable_officer_id', $this->outsider->id)->count());
        $this->assertDatabaseHas('activity_log', ['event' => PsfInventoryImport::EVENT, 'subject_id' => $this->school->id]);
    }

    public function test_placeholders_and_delivery_references_are_not_kept_as_property_numbers(): void
    {
        $this->workbook('107025 - Cutud ES/107025 Cutud ES.xlsx', [
            ['B' => 'unknown', 'E' => 'Laptop', 'G' => 'Dell'],
            ['B' => 'Laptop Computer', 'E' => 'Laptop', 'G' => 'Dell'],
            ['B' => 'DCP Batch 29', 'E' => 'Laptop', 'G' => 'Dell'],
            ['B' => 'DCP Batch 29', 'E' => 'Laptop', 'G' => 'Dell', 'AA' => 'DR-1001'],
            ['B' => 'DCP-2019-LAP-001', 'E' => 'Laptop', 'G' => 'Dell'],
        ]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true])->assertSuccessful();

        $imported = Equipment::query()->with('transactions')->orderBy('id')->get();
        $this->assertSame([null, null, null, null, 'DCP-2019-LAP-001'], $imported->pluck('old_property_no')->all());
        $this->assertSame('DCP Batch 29', $imported[2]->transactions->first()->or_si_dr_iar_no);
        $this->assertSame('DR-1001', $imported[3]->transactions->first()->or_si_dr_iar_no);
        $this->assertStringContainsString('Delivery reference: DCP Batch 29', (string) $imported[3]->remarks);
    }

    public function test_a_school_is_imported_once_and_a_dry_run_writes_nothing(): void
    {
        $this->workbook('107025 - Cutud ES/107025 Cutud ES.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Equipment::query()->count());

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true])->assertSuccessful();
        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => 'importer@deped.gov.ph', '--force' => true])
            ->expectsOutputToContain('Already imported')
            ->assertSuccessful();

        $this->assertSame(1, Equipment::query()->count());
    }

    public function test_misfiled_mismatched_and_unlinked_schools_are_skipped(): void
    {
        $this->workbook('107024 - Belen Homesite ES/107034 - Pulungbulu Elementary School.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);
        $this->workbook('107099 - Unlinked ES/107099 Unlinked ES.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true])
            ->expectsOutputToContain('No file name looks like Air Force City ES')
            ->expectsOutputToContain('No PRIME-linked office has this school ID')
            ->assertSuccessful();

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_a_school_and_its_shs_sharing_an_id_are_told_apart_by_the_file_name(): void
    {
        $main = Office::factory()->create(['office_name' => 'Bonifacio V. Romero HS', 'school_id' => '301042', 'prime_department_id' => $this->primeDepartment('Bonifacio V. Romero HS')]);
        $shs = Office::factory()->create(['office_name' => 'Bonifacio V. Romero HS - SHS', 'school_id' => '301042', 'prime_department_id' => $this->primeDepartment('Bonifacio V. Romero HS - SHS')]);

        $this->workbook('301042 - BVRHS/ICT INVENTORY 2026 Bonifacio V. Romero HS - 301042.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);
        $plans = app(PsfInventoryImport::class)->plan($this->folder);
        $this->assertSame($main->id, $plans[0]['office']->id);

        File::cleanDirectory($this->folder);
        $this->workbook('301042 - BVRHS/Bonifacio V. Romero HS - 301042 (SHS).xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);
        $plans = app(PsfInventoryImport::class)->plan($this->folder);
        $this->assertSame($shs->id, $plans[0]['office']->id);
    }

    public function test_assign_moves_a_misnumbered_file_to_its_real_school(): void
    {
        $pulungbulu = Office::factory()->create(['office_name' => 'Pulungbulu ES', 'school_id' => '500978', 'prime_department_id' => $this->primeDepartment('Pulungbulu ES')]);
        Personnel::factory()->create(['office_id' => $pulungbulu->id, 'position_id' => Position::query()->firstOrCreate(['name' => 'Head Teacher III'])->id, 'inactive' => false]);
        $this->workbook('107024 - Belen Homesite ES/NEW 107034 - Pulungbulu Elementary School.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $this->artisan('equipment:import-psf', [
            'folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true,
            '--assign' => ['NEW 107034 - Pulungbulu Elementary School.xlsx=500978'],
        ])->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['event' => PsfInventoryImport::EVENT, 'subject_id' => $pulungbulu->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => PsfInventoryImport::EVENT, 'subject_id' => $this->otherSchool->id]);
    }

    public function test_a_school_named_with_different_spacing_and_words_still_matches(): void
    {
        $sapangbato = Office::factory()->create(['office_name' => 'Sapangbato NHS', 'school_id' => '301043', 'prime_department_id' => $this->primeDepartment('Sapangbato NHS')]);
        Personnel::factory()->create(['office_id' => $sapangbato->id, 'inactive' => false, 'position_id' => Position::query()->firstOrCreate(['name' => 'Secondary School Principal'])->id]);
        $this->workbook('301043 - Sapangbato High School/Sapangbato High School - 301043.xlsx', []);
        $this->workbook('301043 - Sapangbato High School/301043-Sapang Bato National High School_ict-equipment-inventory-school-template.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $plan = collect(app(PsfInventoryImport::class)->plan($this->folder))->firstWhere('school_id', '301043');

        $this->assertSame($sapangbato->id, $plan['office']?->id);
        $this->assertNull($plan['problem']);
        $this->assertCount(1, $plan['rows']);
    }

    public function test_primes_school_id_wins_over_the_one_stored_in_iris(): void
    {
        $airForce = Office::factory()->create([
            'office_name' => 'Air Force City Elementary School',
            'school_id' => '107034',
            'prime_department_id' => $this->primeDepartment('Air Force City Elementary School', schoolId: 161001),
        ]);
        $this->workbook('161001 - Air Force City ES/Air Force City Elementary School-161001.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);
        $this->workbook('107034 - Air Force City ES/107034 Air Force City ES.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $plans = collect(app(PsfInventoryImport::class)->plan($this->folder))->keyBy('school_id');

        $this->assertSame($airForce->id, $plans['161001']['office']?->id);
        $this->assertNotSame($airForce->id, $plans['107034']['office']?->id);
    }

    public function test_the_fallback_prefers_the_principal_over_an_assistant_and_explains_misses(): void
    {
        $assistant = Personnel::factory()->create([
            'office_id' => $this->school->id, 'last_name' => 'Aaron', 'inactive' => false,
            'position_id' => Position::query()->firstOrCreate(['name' => 'Assistant Secondary School Principal'])->id,
        ]);
        $this->workbook('107025 - Cutud ES/107025 Cutud ES.xlsx', [
            ['E' => 'Laptop', 'G' => 'Dell', 'AC' => 'Someone Else - 4400003'],
            ['E' => 'Laptop', 'G' => 'Dell', 'AC' => 'Nobody Known - 9999999'],
            ['E' => 'Laptop', 'G' => 'Dell', 'AC' => "{$this->teacher->first_name} Q. {$this->teacher->last_name}"],
            ['E' => 'Laptop', 'G' => 'Dell', 'AC' => 'Hyphenated Number - 440-0002'],
        ]);

        $rows = app(PsfInventoryImport::class)->plan($this->folder)[0]['rows'];

        $this->assertSame($this->head->id, $rows[0]['transaction']['accountable_officer_id']);
        $this->assertNotSame($assistant->id, $rows[0]['transaction']['accountable_officer_id']);
        $this->assertStringContainsString('belongs to personnel at Air Force City ES', $rows[0]['notes'][0]);
        $this->assertStringContainsString('not in IRIS personnel', $rows[1]['notes'][0]);
        $this->assertSame($this->teacher->id, $rows[2]['transaction']['accountable_officer_id']);
        $this->assertSame($this->teacher->id, $rows[3]['transaction']['accountable_officer_id']);
    }

    public function test_a_school_without_any_head_is_held_back_when_rows_would_be_unassigned(): void
    {
        Personnel::query()->where('office_id', $this->school->id)->update(['inactive' => true]);
        $this->workbook('107025 - Cutud ES/107025 Cutud ES.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => $this->importer()->email, '--force' => true])
            ->expectsOutputToContain('no officer and the school has no Principal')
            ->assertSuccessful();

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_the_importer_account_is_optional_but_a_mistyped_one_stops_the_run(): void
    {
        $this->workbook('107025 - Cutud ES/107025 Cutud ES.xlsx', [['E' => 'Laptop', 'G' => 'Dell']]);

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--user' => 'nobody@deped.gov.ph', '--force' => true])->assertFailed();
        $this->assertSame(0, Equipment::query()->count());

        $this->artisan('equipment:import-psf', ['folder' => $this->folder, '--force' => true])->assertSuccessful();
        $equipment = Equipment::query()->with('transactions')->sole();
        $this->assertNull($equipment->created_by_user_id);
        $this->assertSame($this->head->id, $equipment->current_accountable_officer_id);
        $this->assertNull($equipment->transactions->first()->recorded_by_user_id);
    }
}

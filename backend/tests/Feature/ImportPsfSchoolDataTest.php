<?php

namespace Tests\Feature;

use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use App\Services\PsfSchoolDataImport;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class ImportPsfSchoolDataTest extends TestCase
{
    use FakesPrime, RefreshDatabase;

    private string $folder;

    private Office $school;

    private Office $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();

        $this->school = Office::factory()->create(['office_name' => 'Cutud ES', 'school_id' => '107025', 'prime_department_id' => $this->primeDepartment('Cutud ES')]);
        $this->otherSchool = Office::factory()->create(['office_name' => 'Air Force City ES', 'school_id' => '107034', 'prime_department_id' => $this->primeDepartment('Air Force City ES')]);

        $this->folder = storage_path('framework/testing/psf-data-'.uniqid());
        File::ensureDirectoryExists($this->folder.'/107025 - Cutud ES');
        $this->workbook($this->folder.'/107025 - Cutud ES/107025 Cutud ES.xlsx');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function workbook(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $profile = $spreadsheet->getActiveSheet()->setTitle('Stakeholder Profile');
        foreach ([
            3 => ['B', 'Governance Level:', 'School'], 7 => ['C', 'School Name:', 'Cutud Elementary School'], 13 => ['C', 'Street:', 'Rizal St.'],
            25 => ['C', 'RO/SDO Chief/School Head:', 'DELA CRUZ, JUAN - 1234567'], 26 => ['C', 'Postion:', 'School Principal I'],
            27 => ['C', 'Email Address:', 'juan.delacruz@deped.gov.ph'], 39 => ['C', 'Longitude:', 120.59], 40 => ['C', 'Latitude:', 15.14],
            44 => ['C', 'Nearby Institutions (Check all that apply):', null], 46 => ['C', 'Barangay Hall', '✓'], 47 => ['C', 'Police Station', null],
            57 => ['C', 'Others (Specify)', 'Public Library'], 58 => ['C', 'Travel Time to Nearest Municipality / City Center', 16],
        ] as $row => [$column, $label, $value]) {
            $profile->setCellValue($column.$row, $label);
            $profile->setCellValue('D'.$row, $value);
        }

        $survey = $spreadsheet->createSheet()->setTitle('Internet Connectivity');
        foreach ([
            4 => ['Are there any internet service provider(s) available in the area?', null, '✓'],
            5 => ['What internet service provider(s) are available in the area? (Check all that apply)', null, null],
            6 => [null, 'GLOBE', '✓'], 7 => [null, 'SMART', null],
        ] as $row => [$question, $option, $answer]) {
            $survey->setCellValue('C'.$row, $question);
            $survey->setCellValue('D'.$row, $option);
            $survey->setCellValue('E'.$row, $answer);
        }

        $accounts = $spreadsheet->createSheet()->setTitle('ISP Account');
        $accounts->fromArray(['isp', 'cost_per_month', 'isp_billing_account_no', 'package_purchased_inclusion', 'subscription_type', 'isp_connection_types', 'overall_signal_quality', 'rate_isp_service'], null, 'A1');
        $accounts->fromArray(['GLOBE', 1500, 'GLB-001', 'Fiber 100 Mbps', 'Postpaid', 'Fiber', 'Stable', '⭐⭐⭐⭐'], null, 'A4');
        $accounts->fromArray(['CONVERGE', 2000, 'CNV-002', 'Fiber 200 Mbps', 'Postpaid', 'Fiber', 'Strong', 5], null, 'A5');

        $tests = $spreadsheet->createSheet()->setTitle('ISP Speed Test Results');
        $tests->fromArray(['isp', 'isp_billing_account_number', 'min_speed', 'max_speed', 'download_speed', 'upload_speed', 'ping', 'timestamp', 'signal_quality'], null, 'A1');
        $tests->fromArray(['CONVERGE', 'CNV-002', null, null, 180, 90, 7, 46066.5, 'Strong'], null, 'A4');

        (new Xlsx($spreadsheet))->save($path);
    }

    public function test_the_sheets_land_on_the_files_own_school_and_only_fill_blanks(): void
    {
        StakeholderProfile::query()->create(['office_id' => $this->school->id, 'street' => 'Typed by the school']);
        $globe = IspProvider::query()->where('name', 'GLOBE')->value('id');
        IspAccount::factory()->create(['office_id' => $this->school->id, 'isp_provider_id' => $globe, 'isp_billing_account_no' => 'GLB-001']);

        $this->artisan('school-data:import-psf', ['folder' => $this->folder, '--force' => true])->assertSuccessful();

        $profile = StakeholderProfile::query()->where('office_id', $this->school->id)->sole();
        $this->assertSame('Typed by the school', $profile->street);
        $this->assertSame('Cutud Elementary School', $profile->school_name);
        $this->assertSame('School Principal I', $profile->chief_position);
        $this->assertSame(16, $profile->travel_time_to_center_minutes);
        $this->assertSame('Public Library', $profile->nearby_institutions_other);
        $this->assertSame([StakeholderLibrary::query()->where('value', 'Barangay Hall')->value('id')], $profile->nearby_institutions);

        $survey = InternetConnectivitySurvey::query()->where('office_id', $this->school->id)->sole();
        $this->assertTrue($survey->has_isp_in_area);
        $this->assertSame([$globe], $survey->available_isps);

        $this->assertSame(2, IspAccount::query()->where('office_id', $this->school->id)->count());
        $converge = IspAccount::query()->where('isp_billing_account_no', 'CNV-002')->sole();
        $this->assertSame(5, $converge->rate_isp_service);
        $this->assertSame(1, IspSpeedTest::query()->where('isp_account_id', $converge->id)->count());

        $this->assertNull(StakeholderProfile::query()->where('office_id', $this->otherSchool->id)->first());
        $this->assertSame(0, IspAccount::query()->where('office_id', $this->otherSchool->id)->count());
    }

    public function test_a_school_is_imported_once_and_a_dry_run_writes_nothing(): void
    {
        $this->artisan('school-data:import-psf', ['folder' => $this->folder, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, IspAccount::query()->count());

        $this->artisan('school-data:import-psf', ['folder' => $this->folder, '--force' => true])->assertSuccessful();
        $this->artisan('school-data:import-psf', ['folder' => $this->folder, '--force' => true])
            ->expectsOutputToContain('Already imported')
            ->assertSuccessful();

        $this->assertSame(2, IspAccount::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => PsfSchoolDataImport::EVENT, 'subject_id' => $this->school->id]);
    }
}

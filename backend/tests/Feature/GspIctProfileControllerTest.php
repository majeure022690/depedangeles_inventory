<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\ConnectivityLibrary;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\GspIctProfile;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class GspIctProfileControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const ALL = [Permission::GspProfilesView, Permission::GspProfilesCreate, Permission::GspProfilesEdit, Permission::GspProfilesDelete, Permission::ReportsGspIct];

    private Office $office;

    private Personnel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create(['office_name' => 'Sample ES', 'office_type' => 'Elementary School', 'school_id' => 107001]);
        $this->officer = Personnel::factory()->create(['office_id' => $this->office->id]);
    }

    private function reporter(): User
    {
        return $this->userWithPermissions(self::ALL);
    }

    private function download(GspIctProfile $profile, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->reporter())->get("/api/gsp-profiles/{$profile->id}/download");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(string $itemType, array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'item_type_id' => ItemType::where('name', $itemType)->valueOrFail('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'non_functional' => false,
            'disposition_status' => 'Normal',
            'non_dcp' => true,
            'dcp_package' => null,
            'dcp_year' => null,
            'source_acquisition' => 'School Division Office',
            'mode_acquisition' => 'DepEd Purchase',
            'source_fund' => 'General Fund (GF)',
            'acquisition_cost' => 30000,
            'current_accountable_officer_id' => $this->officer->id,
            'current_end_user_id' => null,
            'used_by' => null,
            ...$attributes,
        ]);
    }

    private function workbook(TestResponse $response): Spreadsheet
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    private function rowOf(Worksheet $sheet, string $column, string $text): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if (str_contains((string) $sheet->getCell("{$column}{$row}")->getValue(), $text)) {
                return $row;
            }
        }

        $this->fail("'{$text}' not found in column {$column} of {$sheet->getTitle()}.");
    }

    public function test_crud_is_scoped_validated_and_audited(): void
    {
        $user = $this->reporter();
        $payload = ['office_id' => $this->office->id, 'school_year' => '2025-2026', 'as_of' => '2026-03-31', 'level' => 'elementary', 'radio_teachers' => ['grade_1' => ['literacy' => 3, 'numeracy' => 2]], 'dict_free_wifi' => 'with_internet'];

        $id = $this->actingAs($user)->postJson('/api/gsp-profiles', $payload)->assertCreated()->assertJsonPath('data.radio_teachers.grade_1.literacy', 3)->json('data.id');
        $this->actingAs($user)->postJson('/api/gsp-profiles', $payload)->assertUnprocessable()->assertJsonValidationErrors(['school_year']);
        $this->actingAs($user)->putJson("/api/gsp-profiles/{$id}", [...$payload, 'level' => 'junior_high'])->assertOk()->assertJsonPath('data.level', 'junior_high');
        $this->actingAs($user)->getJson('/api/gsp-profiles?school_year=2025-2026')->assertOk()->assertJsonPath('meta.total', 1);

        $foreign = IspAccount::factory()->create(['office_id' => Office::factory()->create()->id]);
        $this->actingAs($user)->putJson("/api/gsp-profiles/{$id}", [...$payload, 'isp_reliability' => [$foreign->id => 'Reliable'], 'radio_teachers' => ['grade_9' => []]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['isp_reliability', 'radio_teachers']);

        $scoped = $this->userWithPermissions(self::ALL, ['office_id' => Office::factory()->create()->id], DataScope::School);
        $this->actingAs($scoped)->getJson("/api/gsp-profiles/{$id}")->assertNotFound();
        $this->download(GspIctProfile::findOrFail($id), $scoped)->assertNotFound();

        $this->actingAs($user)->deleteJson("/api/gsp-profiles/{$id}")->assertNoContent();
        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertDatabaseHas('activity_log', ['description' => "gsp_ict_profile.{$event}", 'subject_id' => $id]);
        }
    }

    public function test_preview_returns_the_iris_tables_for_a_reachable_office(): void
    {
        $this->item('Laptop', ['used_by' => 'Teacher']);
        $this->item('Smart TV');
        $query = http_build_query(['office_id' => $this->office->id, 'school_year' => '2025-2026', 'level' => 'elementary']);

        $response = $this->actingAs($this->userWithPermissions([Permission::GspProfilesView]))->getJson("/api/gsp-profiles/preview?{$query}")->assertOk();

        $this->assertSame(1, $response->json('data.table_1.counts.teacher.elementary.laptop.deped_non_dcp'));
        $this->assertSame(['label' => 'SMART TV', 'dcp' => 0, 'deped_non_dcp' => 1, 'others' => 0], $response->json('data.table_2.1'));
        $this->assertSame([2022, 2023, 2024, 2025], $response->json('data.table_3.years'));
        $this->assertFalse($response->json('data.box_1.has_survey'));

        $scoped = $this->userWithPermissions([Permission::GspProfilesView], ['office_id' => Office::factory()->create()->id], DataScope::School);
        $this->actingAs($scoped)->getJson("/api/gsp-profiles/preview?{$query}")->assertNotFound();
    }

    public function test_download_needs_the_report_permission(): void
    {
        $profile = GspIctProfile::factory()->create(['office_id' => $this->office->id]);

        $this->download($profile, $this->userWithPermissions([Permission::GspProfilesView]))->assertForbidden();
    }

    public function test_gsp_ict_counts_functional_computers_by_user_group_level_and_funding_source(): void
    {
        $this->item('Desktop (package)', ['used_by' => 'Learner', 'non_dcp' => false]);
        $this->item('Desktop (package)', ['used_by' => 'Learner', 'non_dcp' => false]);
        $this->item('Laptop', ['used_by' => 'Teacher']);
        $this->item('Laptop', ['used_by' => 'Non-Teaching', 'source_acquisition' => 'Local Government Unit (LGU)']);
        $this->item('Laptop', ['used_by' => 'Teacher', 'equipment_condition_id' => EquipmentCondition::where('name', 'Unserviceable')->valueOrFail('id')]);
        $this->item('Laptop', ['used_by' => 'Teacher', 'disposition_status' => 'Disposed']);
        $this->item('Smart TV', ['source_acquisition' => "Parent Teacher's Association"]);
        $this->item('Laptop', ['non_dcp' => false, 'dcp_package' => 'eLearning Cart Package', 'dcp_year' => 2024, 'used_by' => 'Learner']);
        $elsewhere = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);
        $this->item('Laptop', ['used_by' => 'Teacher', 'current_accountable_officer_id' => $elsewhere->id]);

        $profile = GspIctProfile::factory()->create(['office_id' => $this->office->id, 'school_head_name' => 'Juan Dela Cruz', 'radio_teachers' => ['grade_3' => ['literacy' => 2, 'numeracy' => 1]]]);
        $response = $this->download($profile)->assertOk();
        $this->assertStringContainsString('GSP_ICT_Report_Sample_ES_2025-2026.xlsx', (string) $response->headers->get('Content-Disposition'));

        $book = $this->workbook($response);
        $cover = $book->getSheetByNameOrThrow('Cover');
        $table1 = $book->getSheetByNameOrThrow('Table 1');
        $tables = $book->getSheetByNameOrThrow('Tables 2 to 4');

        $this->assertSame('GOVERNMENT SCHOOL PROFILE, SY 2025–2026', $cover->getCell('A1')->getValue());
        $this->assertSame('SAMPLE ES - 107001', $cover->getCell('A3')->getValue());
        $this->rowOf($cover, 'B', 'JUAN DELA CRUZ');
        $this->assertSame('SY 2025–2026, as of March 31, 2026', $table1->getCell('A2')->getValue());

        $this->assertSame(2, $table1->getCell('B9')->getValue());
        $this->assertSame(1, $table1->getCell('B10')->getValue());
        $this->assertSame(0, $table1->getCell('C15')->getValue());
        $this->assertSame(1, $table1->getCell('C32')->getValue());
        $this->assertSame(1, $table1->getCell('D53')->getValue());

        $this->assertSame(1, $tables->getCell('D6')->getValue());
        $eCart = $this->rowOf($tables, 'A', 'TYPES OF E-CART COMPONENT');
        $this->assertSame('2024-01', $tables->getCell("D{$eCart}")->getValue());
        $this->assertSame(1, $tables->getCell('D'.($eCart + 1))->getValue());

        $grade3 = $this->rowOf($tables, 'A', 'Grade 3');
        $this->assertSame([2, 1], [$tables->getCell("B{$grade3}")->getValue(), $tables->getCell("C{$grade3}")->getValue()]);
        $this->rowOf($book->getSheetByNameOrThrow('Table 1 Definitions'), 'A', 'DepEd DCP - These are computers procured through the DepEd Computerization Program.');

        $this->assertDatabaseHas('activity_log', ['event' => 'report.generated', 'subject_id' => $this->office->id]);
    }

    public function test_gsp_ict_fills_internet_connectivity_and_ticks_survey_answers(): void
    {
        $pldt = IspProvider::firstOrCreate(['name' => 'PLDT'], ['is_active' => true]);
        $globe = IspProvider::firstOrCreate(['name' => 'GLOBE'], ['is_active' => true]);
        $account = IspAccount::factory()->create([
            'office_id' => $this->office->id,
            'isp_provider_id' => $pldt->id,
            'inactive_contract' => false,
            'isp_connection_type' => 'Fiber',
            'school_area_coverage' => 'All (Areawide)',
            'purpose_of_subscription' => 'For administrative use',
            'fund_source' => 'Maintenance and Other Operating Expenses (MOOE)',
            'cost_per_month' => 1888,
            'subscriber_speed_download' => 100,
            'subscriber_speed_upload' => 50,
        ]);
        IspSpeedTest::factory()->create(['isp_account_id' => $account->id, 'download_speed' => 70, 'upload_speed' => 40, 'ping' => 15, 'tested_at' => now()]);
        InternetConnectivitySurvey::create([
            'office_id' => $this->office->id,
            'available_isps' => [$globe->id],
            'coverage_areas' => [ConnectivityLibrary::where('value', 'Library')->valueOrFail('id')],
        ]);

        $profile = GspIctProfile::factory()->create([
            'office_id' => $this->office->id,
            'dict_free_wifi' => 'without_internet',
            'dict_free_wifi_installed' => 'October 2023',
            'isp_reliability' => [(string) $account->id => 'Unstable'],
        ]);
        $box = $this->workbook($this->download($profile)->assertOk())->getSheetByNameOrThrow('Box 1');

        $this->assertSame(['PLDT', 'Fiber Optic', 'Shared', 'Administrative'], [$box->getCell('A12')->getValue(), $box->getCell('B12')->getValue(), $box->getCell('C12')->getValue(), $box->getCell('D12')->getValue()]);
        $this->assertSame('MOOE', $box->getCell('F12')->getValue());
        $this->assertSame('Good', $box->getCell('L12')->getValue());
        $this->assertSame('Unstable', $box->getCell('M12')->getValue());
        $wifi = $this->rowOf($box, 'B', 'Yes, but without internet connection');
        $this->assertStringStartsWith('☑', $box->getCell("B{$wifi}")->getValue());
        $this->assertStringContainsString('October 2023', $box->getCell('B'.($wifi + 1))->getValue());
        $this->assertSame('☑  GLOBE', $box->getCell('B5')->getValue());
        $this->assertSame('☐  PLDT', $box->getCell('B7')->getValue());
        $coverage = $this->rowOf($box, 'J', 'Library');
        $this->assertSame('☑  Library', $box->getCell("J{$coverage}")->getValue());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\PropertyCategory;
use App\Enums\PropertySource;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PropertyItem;
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

class ReportControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Personnel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create(['office_name' => 'Sample ES', 'office_type' => 'Elementary School', 'school_id' => 107001]);
        $this->officer = Personnel::factory()->create(['office_id' => $this->office->id, 'first_name' => 'Maria', 'middle_name' => 'Santos', 'last_name' => 'Reyes', 'suffix' => null]);
    }

    private function reporter(DataScope $scope = DataScope::All, ?int $officeId = null): User
    {
        return $this->userWithPermissions([Permission::ReportsGspIct, Permission::ReportsRpcppe], ['office_id' => $officeId], $scope);
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

    /**
     * @param  array<string, mixed>  $query
     */
    private function rpcppe(User $user, array $query = ['school_year' => '2025-2026']): TestResponse
    {
        return $this->actingAs($user)->get("/api/reports/rpcppe/{$this->office->id}?".http_build_query($query));
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson("/api/reports/rpcppe/{$this->office->id}?school_year=2025-2026")->assertUnauthorized();
        $this->getJson('/api/reports/options')->assertUnauthorized();
    }

    public function test_rpcppe_needs_its_own_permission(): void
    {
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson("/api/reports/rpcppe/{$this->office->id}?school_year=2025-2026")->assertForbidden();
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson('/api/reports/options')->assertForbidden();
    }

    public function test_school_scoped_user_cannot_report_on_another_office(): void
    {
        $user = $this->reporter(DataScope::School, Office::factory()->create()->id);

        $this->actingAs($user)->getJson("/api/reports/rpcppe/{$this->office->id}?school_year=2025-2026")->assertNotFound();
    }

    public function test_options_list_only_reachable_offices_and_their_accountable_officers(): void
    {
        $this->item('Laptop');
        $user = $this->reporter(DataScope::School, $this->office->id);
        Office::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/reports/options?office_id={$this->office->id}")->assertOk();

        $this->assertSame([$this->office->id], array_column($response->json('data.offices'), 'value'));
        $this->assertSame([$this->officer->id], array_column($response->json('data.accountable_officers'), 'value'));
    }

    public function test_rpcppe_combines_equipment_and_school_property_by_sheet(): void
    {
        $teacher = Personnel::factory()->create(['office_id' => $this->office->id, 'first_name' => 'Ana', 'middle_name' => 'Cruz', 'last_name' => 'Lopez']);
        $this->item('Desktop (package)', ['acquisition_cost' => 75000, 'property_no' => 'PPE-001']);
        $this->item('Laptop', ['acquisition_cost' => 30000, 'property_no' => 'SE-001', 'received_date' => '2024-06-13']);
        $this->item('Laptop', ['acquisition_cost' => 20000, 'property_no' => 'SE-002', 'disposition_status' => 'Lost', 'received_date' => '2025-01-10']);
        $this->item('Laptop', ['property_no' => 'GONE-001', 'disposition_status' => 'Transferred']);
        $this->item('Copier / Xerox Machine', ['acquisition_cost' => 60000, 'property_no' => 'OFC-001']);
        PropertyItem::factory()->create([
            'office_id' => $this->office->id, 'category' => PropertyCategory::FurnitureFixtures, 'article' => 'Monoblock Chair',
            'quantity' => 40, 'on_hand_count' => 38, 'unit_value' => 350, 'source' => PropertySource::Lgu,
            'accountable_officer_id' => $this->officer->id, 'end_user_id' => $teacher->id, 'grade_level' => 'Grade 1', 'date_acquired' => null,
        ]);
        PropertyItem::factory()->create([
            'office_id' => $this->office->id, 'category' => PropertyCategory::Books, 'article' => 'English', 'grade_level' => 'Grade 2',
            'description' => 'English Learner’s Material', 'quantity' => 30, 'on_hand_count' => 28, 'accountable_officer_id' => $this->officer->id,
        ]);

        $response = $this->rpcppe($this->reporter(), [
            'school_year' => '2025-2026',
            'accountable_officer_id' => $this->officer->id,
            'assumed_on' => '2025-01-02',
            'prepared_by' => 'Preparer Name',
        ])->assertOk();

        $book = $this->workbook($response);
        $ppe = $book->getSheetByNameOrThrow('IT');
        $semi = $book->getSheetByNameOrThrow('IT Semi-Exp');
        $furniture = $book->getSheetByNameOrThrow('F&F');

        $this->assertSame('MARIA S. REYES', $ppe->getCell('B6')->getValue());
        $this->assertSame('JANUARY 2, 2025', $ppe->getCell('P6')->getValue());
        $this->assertSame('PPE-001', $ppe->getCell('C11')->getValue());
        $this->assertSame('=SUM(F11:F11)', $ppe->getCell('F12')->getValue());

        $this->assertSame(['SE-001', 'SE-002'], [$semi->getCell('C11')->getValue(), $semi->getCell('C12')->getValue()]);
        $this->assertSame(0, $semi->getCell('I12')->getValue());
        $this->assertSame('=I12-H12', $semi->getCell('J12')->getValue());
        $this->assertSame('✓', $semi->getCell('L11')->getValue());
        $this->rowOf($semi, 'B', 'PREPARER NAME');

        $this->assertSame('OFC-001', $book->getSheetByNameOrThrow('Office Eqpt')->getCell('C11')->getValue());

        $this->assertSame('Monoblock Chair', $furniture->getCell('A11')->getValue());
        $this->assertSame([40, 38], [$furniture->getCell('H11')->getValue(), $furniture->getCell('I11')->getValue()]);
        $this->assertSame('✓', $furniture->getCell('N11')->getValue());

        $books = $book->getSheetByNameOrThrow('Books');
        $this->assertSame(['English', 'Grade 2', 30, 28], [$books->getCell('A10')->getValue(), $books->getCell('B10')->getValue(), $books->getCell('E10')->getValue(), $books->getCell('F10')->getValue()]);

        $attachment = $book->getSheetByNameOrThrow('F&F per Teacher');
        $this->assertSame('Monoblock Chair', $attachment->getCell('B8')->getValue());
        $this->assertSame('Grade 1', $attachment->getCell('A9')->getValue());
        $this->assertSame('Lopez, Ana C.', $attachment->getCell('A10')->getValue());
        $this->assertSame(40, $attachment->getCell('B10')->getValue());

        foreach ([$ppe, $semi] as $sheet) {
            for ($row = 11; $row <= $sheet->getHighestRow(); $row++) {
                $this->assertNotSame('GONE-001', $sheet->getCell("C{$row}")->getValue());
            }
        }
    }

    public function test_rpcppe_rejects_an_officer_from_another_office_and_a_malformed_school_year(): void
    {
        $elsewhere = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);

        $this->actingAs($this->reporter())
            ->getJson("/api/reports/rpcppe/{$this->office->id}?school_year=2025&accountable_officer_id={$elsewhere->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year', 'accountable_officer_id']);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coverage for App\Observers\EquipmentObserver and
 * Api\Management\EquipmentController::qrCode() — see both doc-comments for the full
 * rationale (why a URL rather than a bare property_no, why it's populated
 * via an Observer rather than accepted as input, why the `updated` hook
 * exists at all given the value never needs to change once set).
 *
 * Equipment's Tier 1 fields are real FKs as of the lookup-normalization
 * ADR's Step 2 — payloads here use real seeded reference-table ids, not
 * the old Lookup/LookupType system.
 */
class EquipmentQrCodeTest extends TestCase
{
    use RefreshDatabase;

    private function makeEquipmentPayload(array $overrides = []): array
    {
        return array_merge([
            'property_no' => 'AC-2026-IT-LT-000123',
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ], $overrides);
    }

    public function test_qr_code_is_auto_generated_on_create_and_encodes_the_edit_url(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->postJson('/api/equipment', $this->makeEquipmentPayload())->assertCreated();

        $equipment = Equipment::where('property_no', 'AC-2026-IT-LT-000123')->firstOrFail();

        $this->assertNotNull($equipment->qr_code);
        $this->assertSame(route('equipment.edit', $equipment), $equipment->qr_code);
    }

    public function test_qr_code_is_not_accepted_as_user_input(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->postJson('/api/equipment', $this->makeEquipmentPayload([
            'qr_code' => 'http://attacker.example/not-a-real-record',
        ]))->assertCreated();

        $equipment = Equipment::where('property_no', 'AC-2026-IT-LT-000123')->firstOrFail();

        $this->assertSame(route('equipment.edit', $equipment), $equipment->qr_code);
    }

    public function test_qr_code_is_backfilled_on_update_for_a_record_that_predates_the_feature(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        // Simulate a pre-existing row created before this feature shipped:
        // insert directly via the query builder so no model event (and
        // therefore no Observer) fires, leaving qr_code null exactly like
        // a legacy record would be. The legacy lookup-normalization string
        // columns this test used to populate directly (item,
        // brand_manufacturer, category, classification, equipment_condition)
        // were dropped in that ADR's Step 4 cleanup — every row, including
        // ones predating this QR code feature, now carries the Tier 1 FK
        // columns instead (NOT NULL at the schema level), so the "legacy
        // row" this simulates is legacy only with respect to qr_code, not
        // to the lookup-normalization cutover.
        $id = DB::table('equipment')->insertGetId([
            'property_no' => 'AC-2020-IT-LT-000001',
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 40000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
            'non_dcp' => false,
            'non_functional' => false,
            'under_warranty' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $equipment = Equipment::findOrFail($id);
        $this->assertNull($equipment->qr_code);

        $this->actingAs($user)->putJson(
            "/api/equipment/{$equipment->id}",
            $this->makeEquipmentPayload(['property_no' => 'AC-2020-IT-LT-000001']),
        )->assertOk();

        $this->assertSame(route('equipment.edit', $equipment), $equipment->fresh()->qr_code);
    }

    public function test_qr_code_image_endpoint_returns_a_png_for_a_user_with_equipment_view(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $equipment = Equipment::factory()->create([
            'disposition_status' => 'Normal',
        ]);

        $response = $this->actingAs($user)->get("/api/equipment/{$equipment->id}/qr-code");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
    }

    public function test_qr_code_content_disposition_survives_quotes_and_crlf_in_property_no(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $equipment = Equipment::factory()->create([
            'property_no' => "PN\"1\r\nX-Injected: yes/é",
            'disposition_status' => 'Normal',
        ]);

        $response = $this->actingAs($user)->get("/api/equipment/{$equipment->id}/qr-code");

        $response->assertOk();
        $response->assertHeaderMissing('X-Injected');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringStartsWith('inline; filename=equipment-PN_1-X-Injected__yes-__-qr.png;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
    }

    public function test_qr_code_image_endpoint_is_forbidden_without_equipment_view(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo(Permission::PersonnelView->value);

        $equipment = Equipment::factory()->create([
            'disposition_status' => 'Normal',
        ]);

        $this->actingAs($user)->getJson("/api/equipment/{$equipment->id}/qr-code")
            ->assertForbidden()
            ->assertJsonMissingPath('code');
    }
}

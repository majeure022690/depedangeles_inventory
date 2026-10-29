<?php

namespace Tests\Feature;

use App\Enums\DashboardEquipmentGroup;
use App\Enums\EquipmentLibraryType;
use App\Enums\Permission;
use App\Enums\PersonnelLibraryType;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentClassification;
use App\Models\EquipmentLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * One generic endpoint set over the 13 config/reference-data.php tables.
 * Tier 1 `name` is editable; Tier 2 `type`/`value` are fixed once
 * created. Deleting a row still referenced anywhere answers 409.
 */
class ReferenceDataControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return $this->userWithPermissions([Permission::ReferenceDataManage]);
    }

    private function withoutReferenceDataManage(): User
    {
        return $this->userWithAllPermissions([Permission::ReferenceDataManage]);
    }

    private function unitOfMeasure(string $value, int $sortOrder = 0): EquipmentLibrary
    {
        return EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => $value,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function activityFor(string $description, int $subjectId, string $subjectType = ItemType::class): Activity
    {
        return Activity::query()->where('description', $description)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->sole();
    }

    public function test_every_endpoint_is_forbidden_without_reference_data_manage(): void
    {
        $outsider = $this->withoutReferenceDataManage();
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($outsider)->getJson('/api/reference-data')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/reference-data/item-types')->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/reference-data/item-types', ['name' => 'Tablet'])->assertForbidden();
        $this->actingAs($outsider)->patchJson("/api/reference-data/item-types/{$itemType->id}", ['name' => 'Renamed', 'sort_order' => 1, 'is_active' => true])->assertForbidden();
        $this->actingAs($outsider)->deleteJson("/api/reference-data/item-types/{$itemType->id}")->assertForbidden();

        $this->assertSame('Laptop', $itemType->fresh()->name);
        $this->assertDatabaseMissing('item_types', ['name' => 'Tablet']);
    }

    public function test_index_lists_all_13_registry_tables_with_row_counts(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);
        Office::create(['office_name' => 'Test ES']);

        $response = $this->actingAs($this->admin())->getJson('/api/reference-data')
            ->assertOk()
            ->assertJsonCount(13, 'data.tables')
            ->assertJsonPath('data.office_count', 1);

        $tables = collect($response->json('data.tables'));
        $this->assertSame(['key' => 'item-types', 'label' => 'Item Types', 'tier' => 1, 'row_count' => 2], $tables->firstWhere('key', 'item-types'));
        $this->assertSame(2, $tables->firstWhere('key', 'equipment-libraries')['tier']);
    }

    public function test_unknown_tables_are_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/reference-data/not-a-real-table')->assertNotFound();
        $this->actingAs($admin)->patchJson('/api/reference-data/not-a-real-table/1', ['sort_order' => 1, 'is_active' => true])->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/reference-data/item-types/999999')->assertNotFound();
    }

    public function test_show_lists_tier1_rows_and_supports_search(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);
        ItemType::create(['name' => 'Projector', 'sort_order' => 2, 'is_active' => true]);

        $this->actingAs($this->admin())->getJson('/api/reference-data/item-types')
            ->assertOk()
            ->assertJsonPath('meta.table', ['key' => 'item-types', 'label' => 'Item Types', 'tier' => 1, 'types' => null, 'dashboard_groups' => DashboardEquipmentGroup::options(), 'classification_defaults' => false])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Laptop')
            ->assertJsonMissingPath('data.0.value')
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'sort_order', 'is_active']]]);

        $this->actingAs($this->admin())->getJson('/api/reference-data/item-types?search=Desk')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Desktop');
    }

    public function test_show_lists_tier2_rows_and_supports_search_and_type_filter(): void
    {
        $this->unitOfMeasure('Unit');
        $this->unitOfMeasure('Piece', 1);
        EquipmentLibrary::create(['type' => EquipmentLibraryType::DispositionStatus->value, 'value' => 'Serviceable', 'sort_order' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->admin())->getJson('/api/reference-data/equipment-libraries')
            ->assertOk()
            ->assertJsonPath('meta.table.tier', 2)
            ->assertJsonCount(10, 'meta.table.types')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['id', 'type', 'value', 'description', 'sort_order', 'is_active']]]);

        $this->assertSame(['value', 'label'], array_keys($response->json('meta.table.types.0')));

        $this->actingAs($this->admin())->getJson('/api/reference-data/equipment-libraries?type='.EquipmentLibraryType::DispositionStatus->value)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'Serviceable')
            ->assertJsonPath('data.0.type', EquipmentLibraryType::DispositionStatus->value);

        $this->actingAs($this->admin())->getJson('/api/reference-data/equipment-libraries?search=Piece')->assertJsonCount(1, 'data');
    }

    public function test_admin_can_rename_and_edit_a_tier1_row_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $itemType = ItemType::create(['name' => 'Laptp', 'description' => 'Typo in the name', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($admin)->patchJson("/api/reference-data/item-types/{$itemType->id}", [
            'name' => 'Laptop',
            'description' => 'Corrected.',
            'sort_order' => 5,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data', ['id' => $itemType->id, 'name' => 'Laptop', 'abbreviation' => null, 'dashboard_group' => null, 'description' => 'Corrected.', 'sort_order' => 5, 'is_active' => false]);

        $activity = $this->activityFor('reference_data.updated', $itemType->id);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('item-types', $activity->properties['table']);
        $this->assertSame('Laptp', $activity->attribute_changes['old']['name']);
        $this->assertSame('Laptop', $activity->attribute_changes['attributes']['name']);
    }

    public function test_tier1_update_rejects_a_name_duplicating_another_row_in_the_same_table(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        $desktop = ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())->patchJson("/api/reference-data/item-types/{$desktop->id}", ['name' => 'Laptop', 'sort_order' => 1, 'is_active' => true])
            ->assertJsonValidationErrors('name');

        $this->assertSame('Desktop', $desktop->fresh()->name);
    }

    public function test_tier1_update_allows_keeping_its_own_name_and_requires_sort_order(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->patchJson("/api/reference-data/item-types/{$itemType->id}", ['name' => 'Laptop', 'sort_order' => 3, 'is_active' => true])->assertOk();
        $this->assertSame(3, $itemType->fresh()->sort_order);

        $this->actingAs($this->admin())->patchJson("/api/reference-data/item-types/{$itemType->id}", ['name' => 'Laptop', 'is_active' => true])
            ->assertJsonValidationErrors('sort_order');
    }

    public function test_admin_can_edit_a_tier2_row_but_type_and_value_are_ignored_even_when_submitted(): void
    {
        $library = $this->unitOfMeasure('Unit');

        $this->actingAs($this->admin())->patchJson("/api/reference-data/equipment-libraries/{$library->id}", [
            'type' => EquipmentLibraryType::DispositionStatus->value,
            'value' => 'Renamed',
            'name' => 'Ignored, Tier 2 has no name column',
            'description' => 'Clarified.',
            'sort_order' => 2,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.value', 'Unit');

        $library->refresh();
        $this->assertSame(EquipmentLibraryType::UnitOfMeasure, $library->type);
        $this->assertSame('Unit', $library->value);
        $this->assertSame('Clarified.', $library->description);
        $this->assertFalse($library->is_active);

        $activity = $this->activityFor('reference_data.updated', $library->id, EquipmentLibrary::class);
        $this->assertSame('equipment-libraries', $activity->properties['table']);
        $this->assertArrayNotHasKey('name', $activity->attribute_changes['attributes']);
        $this->assertArrayNotHasKey('value', $activity->attribute_changes['attributes']);
    }

    public function test_admin_can_create_a_tier1_row_and_it_is_audited(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/reference-data/item-types', [
            'name' => 'Tablet',
            'abbreviation' => 'TBL',
            'description' => 'Handheld touchscreen device.',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Tablet')
            ->assertJsonPath('data.abbreviation', 'TBL')
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.is_active', true);

        $activity = $this->activityFor('reference_data.created', $response->json('data.id'));
        $this->assertSame('created', $activity->event);
        $this->assertSame('item-types', $activity->properties['table']);
        $this->assertSame('Tablet', $activity->attribute_changes['attributes']['name']);
        $this->assertSame('TBL', $activity->attribute_changes['attributes']['abbreviation']);
    }

    public function test_item_type_abbreviation_is_required_validated_unique_and_audited(): void
    {
        $admin = $this->admin();
        $url = '/api/reference-data/item-types';
        ItemType::create(['name' => 'Laptop', 'abbreviation' => 'LPT', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($admin)->postJson($url, ['name' => 'Tablet'])->assertJsonValidationErrors('abbreviation');
        foreach (['tbl', 'TB', 'TBLT', 'T-L', 'LPT'] as $invalid) {
            $this->actingAs($admin)->postJson($url, ['name' => 'Tablet', 'abbreviation' => $invalid])
                ->assertJsonValidationErrors('abbreviation');
        }
        $this->assertTrue(Validator::make(['abbreviation' => "TBL\n"], ['abbreviation' => StoreReferenceDataRequest::abbreviationRules()])->fails());
        $this->assertFalse(ItemType::where('name', 'Tablet')->exists());

        $id = $this->actingAs($admin)->postJson($url, ['name' => 'Tablet', 'abbreviation' => '2T1'])
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', '2T1')
            ->json('data.id');

        $this->actingAs($admin)->patchJson("{$url}/{$id}", ['name' => 'Tablet', 'abbreviation' => 'LPT', 'sort_order' => 0, 'is_active' => true])
            ->assertJsonValidationErrors('abbreviation');
        $this->actingAs($admin)->patchJson("{$url}/{$id}", ['name' => 'Tablet', 'abbreviation' => null, 'sort_order' => 0, 'is_active' => true])
            ->assertJsonValidationErrors('abbreviation');
        $this->actingAs($admin)->patchJson("{$url}/{$id}", ['name' => 'Tablet', 'abbreviation' => '2T1', 'sort_order' => 0, 'is_active' => true])
            ->assertOk();
        $this->actingAs($admin)->patchJson("{$url}/{$id}", ['name' => 'Tablet', 'abbreviation' => 'TBL', 'sort_order' => 0, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', 'TBL');

        $activity = $this->activityFor('reference_data.updated', $id);
        $this->assertSame('2T1', $activity->attribute_changes['old']['abbreviation']);
        $this->assertSame('TBL', $activity->attribute_changes['attributes']['abbreviation']);

        $this->actingAs($this->userWithPermissions([]))->postJson($url, ['name' => 'Phone', 'abbreviation' => 'PHN'])->assertForbidden();
    }

    public function test_item_types_read_and_write_a_validated_dashboard_group_and_it_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/reference-data/item-types', ['name' => 'Phablet', 'abbreviation' => 'PHB', 'dashboard_group' => 'not_a_group'])
            ->assertJsonValidationErrors('dashboard_group');

        $id = $this->actingAs($admin)->postJson('/api/reference-data/item-types', ['name' => 'Phablet', 'abbreviation' => 'PHB', 'dashboard_group' => 'tablet'])
            ->assertCreated()
            ->assertJsonPath('data.dashboard_group', 'tablet')
            ->json('data.id');

        $this->actingAs($admin)->patchJson("/api/reference-data/item-types/{$id}", ['name' => 'Phablet', 'dashboard_group' => null, 'sort_order' => 0, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.dashboard_group', null);

        $this->assertNull(ItemType::findOrFail($id)->dashboard_group);
        $activity = $this->activityFor('reference_data.updated', $id);
        $this->assertSame('tablet', $activity->attribute_changes['old']['dashboard_group']);
        $this->assertNull($activity->attribute_changes['attributes']['dashboard_group']);

        $this->actingAs($admin)->getJson('/api/reference-data/brands')->assertJsonPath('meta.table.dashboard_groups', null);
    }

    public function test_equipment_classifications_read_and_write_validated_defaults_and_they_are_audited(): void
    {
        $admin = $this->admin();
        $url = '/api/reference-data/equipment-classifications';

        $this->actingAs($admin)->postJson($url, ['name' => 'Furniture', 'default_useful_life' => 'five', 'default_uacs' => str_repeat('9', 256)])
            ->assertJsonValidationErrors(['default_useful_life', 'default_uacs']);

        $id = $this->actingAs($admin)->postJson($url, [
            'name' => 'Furniture',
            'default_gl_sl_code' => '10605030',
            'default_uacs' => '1060503000',
            'default_useful_life' => 5,
        ])->assertCreated()
            ->assertJsonMissingPath('data.default_gl_sl_code')
            ->assertJsonPath('data.default_uacs', '1060503000')
            ->assertJsonPath('data.default_useful_life', 5)
            ->json('data.id');

        $this->actingAs($admin)->patchJson("{$url}/{$id}", [
            'name' => 'Furniture', 'default_useful_life' => 10, 'default_uacs' => null, 'sort_order' => 0, 'is_active' => true,
        ])->assertOk()
            ->assertJsonMissingPath('data.default_gl_sl_code')
            ->assertJsonPath('data.default_uacs', null)
            ->assertJsonPath('data.default_useful_life', 10);

        $activity = $this->activityFor('reference_data.updated', $id, EquipmentClassification::class);
        $this->assertSame(5, $activity->attribute_changes['old']['default_useful_life']);
        $this->assertSame(10, $activity->attribute_changes['attributes']['default_useful_life']);
        $this->assertNull($activity->attribute_changes['attributes']['default_uacs']);
        $this->assertSame('1060503000', $this->activityFor('reference_data.created', $id, EquipmentClassification::class)->attribute_changes['attributes']['default_uacs']);
        $this->assertNull(EquipmentClassification::findOrFail($id)->default_gl_sl_code);

        $this->actingAs($admin)->getJson($url)
            ->assertJsonPath('meta.table.classification_defaults', true)
            ->assertJsonPath('data.0.default_useful_life', 10);

        $this->actingAs($admin)->postJson('/api/reference-data/brands', ['name' => 'Acer', 'default_uacs' => '1'])
            ->assertCreated()
            ->assertJsonMissingPath('data.default_uacs');
        $this->assertArrayNotHasKey('default_uacs', Activity::query()->where('description', 'reference_data.created')->where('subject_type', Brand::class)->sole()->attribute_changes['attributes']);
    }

    public function test_store_rejects_a_tier1_name_duplicating_another_row_in_the_same_table(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->postJson('/api/reference-data/item-types', ['name' => 'Laptop'])->assertJsonValidationErrors('name');

        $this->assertSame(1, ItemType::where('name', 'Laptop')->count());
    }

    public function test_admin_can_create_a_tier2_row_with_a_valid_type(): void
    {
        $this->actingAs($this->admin())->postJson('/api/reference-data/equipment-libraries', [
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Lot',
        ])->assertCreated()
            ->assertJsonPath('data.type', EquipmentLibraryType::UnitOfMeasure->value)
            ->assertJsonPath('data.value', 'Lot');

        $this->assertSame(EquipmentLibraryType::UnitOfMeasure, EquipmentLibrary::where('value', 'Lot')->sole()->type);
    }

    public function test_tier2_store_validates_type_and_per_type_uniqueness(): void
    {
        $admin = $this->admin();
        $this->unitOfMeasure('Unit');

        $this->actingAs($admin)->postJson('/api/reference-data/equipment-libraries', ['type' => 'not-a-real-type', 'value' => 'Lot'])
            ->assertJsonValidationErrors('type');
        $this->actingAs($admin)->postJson('/api/reference-data/equipment-libraries', ['type' => EquipmentLibraryType::UnitOfMeasure->value, 'value' => 'Unit'])
            ->assertJsonValidationErrors('value');

        // Same value under a different type is a different vocabulary.
        $this->actingAs($admin)->postJson('/api/reference-data/equipment-libraries', ['type' => EquipmentLibraryType::DispositionStatus->value, 'value' => 'Unit'])
            ->assertCreated();
        $this->assertSame(2, EquipmentLibrary::where('value', 'Unit')->count());
    }

    public function test_admin_can_hard_delete_an_unreferenced_row_and_it_is_audited(): void
    {
        $itemType = ItemType::create(['name' => 'Fax Machine', 'sort_order' => 0, 'is_active' => true]);
        $library = $this->unitOfMeasure('Lot');
        $provider = IspProvider::create(['name' => 'Globe', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/item-types/{$itemType->id}")->assertNoContent();
        $this->actingAs($this->admin())->deleteJson("/api/reference-data/equipment-libraries/{$library->id}")->assertNoContent();
        $this->actingAs($this->admin())->deleteJson("/api/reference-data/isp-providers/{$provider->id}")->assertNoContent();

        $this->assertDatabaseMissing('item_types', ['id' => $itemType->id]);
        $this->assertDatabaseMissing('equipment_libraries', ['id' => $library->id]);
        $this->assertDatabaseMissing('isp_providers', ['id' => $provider->id]);
        $this->assertSame('item-types', $this->activityFor('reference_data.deleted', $itemType->id)->properties['table']);
        $this->assertSame('equipment-libraries', $this->activityFor('reference_data.deleted', $library->id, EquipmentLibrary::class)->properties['table']);
    }

    public function test_tier1_delete_is_a_409_when_the_fk_still_references_it(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        Equipment::factory()->create(['item_type_id' => $itemType->id]);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/item-types/{$itemType->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This value is still in use and cannot be deleted — deactivate it instead.');

        $this->assertDatabaseHas('item_types', ['id' => $itemType->id]);
        $this->assertDatabaseMissing('activity_log', ['description' => 'reference_data.deleted']);
    }

    public function test_isp_provider_delete_is_a_409_when_referenced_only_via_json_survey_columns(): void
    {
        $provider = IspProvider::create(['name' => 'PLDT', 'sort_order' => 0, 'is_active' => true]);
        $office = Office::create(['office_name' => 'Test Elementary School']);
        InternetConnectivitySurvey::create(['office_id' => $office->id, 'available_isps' => [$provider->id]]);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/isp-providers/{$provider->id}")->assertConflict();

        $this->assertDatabaseHas('isp_providers', ['id' => $provider->id]);
    }

    public function test_tier2_delete_is_a_409_when_referenced_via_a_string_column(): void
    {
        $library = $this->unitOfMeasure('Unit');
        Equipment::factory()->create(['uom' => 'Unit']);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/equipment-libraries/{$library->id}")->assertConflict();

        $this->assertDatabaseHas('equipment_libraries', ['id' => $library->id]);
    }

    public function test_used_by_delete_is_a_409_while_equipment_uses_it_and_allowed_once_free(): void
    {
        $used = EquipmentLibrary::create(['type' => EquipmentLibraryType::UsedBy->value, 'value' => 'Learner', 'sort_order' => 0, 'is_active' => true]);
        $free = EquipmentLibrary::create(['type' => EquipmentLibraryType::UsedBy->value, 'value' => 'Visitor', 'sort_order' => 1, 'is_active' => true]);
        Equipment::factory()->create(['used_by' => 'Learner']);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/equipment-libraries/{$used->id}")->assertConflict();
        $this->actingAs($this->admin())->deleteJson("/api/reference-data/equipment-libraries/{$free->id}")->assertNoContent();

        $this->assertDatabaseHas('equipment_libraries', ['id' => $used->id]);
    }

    public function test_tier2_delete_is_a_409_when_referenced_via_a_json_array_column(): void
    {
        $library = PersonnelLibrary::create([
            'type' => PersonnelLibraryType::TeachersFundingSource->value,
            'value' => 'SEF',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        Personnel::factory()->create(['fund_source' => [$library->id]]);

        $this->actingAs($this->admin())->deleteJson("/api/reference-data/personnel-libraries/{$library->id}")->assertConflict();

        $this->assertDatabaseHas('personnel_libraries', ['id' => $library->id]);
    }
}

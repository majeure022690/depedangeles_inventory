<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Enums\PersonnelLibraryType;
use App\Models\AuditLog;
use App\Models\Equipment;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coverage for the reference-data.manage admin screen — the successor to
 * lookups.manage/LookupController (see docs/architecture-decisions/
 * lookup-normalization.md, Question 3 and Step 2 item 8 / Step 3 item 5).
 * ONE generic controller drives all 13 tables from config/reference-data.php,
 * so — mirroring how LookupControllerTest covered one table for all 35 old
 * lookup types, and ReferenceDataPolicyTest covered one Tier 1 + one
 * Tier 2 model for the Policy — this exercises `item-types` (Tier 1) and
 * `equipment-libraries` (Tier 2) as representatives of their tier. The
 * controller has no per-table branching beyond tier, so these two stand
 * in for all 13.
 */
class ReferenceDataControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function encoder(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encoder');

        return $user;
    }

    /**
     * reference-data/Index.vue and reference-data/Show.vue don't exist yet
     * (Frontend builds them against the prop contract this controller
     * establishes) — same situation UserControllerTest/LookupControllerTest
     * already solved.
     */
    private function inertiaGet(string $url): TestResponse
    {
        $manifest = public_path('build/manifest.json');
        $version = file_exists($manifest) ? hash_file('xxh128', $manifest) : null;

        return $this->get($url, array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]));
    }

    private function assertInertiaJson(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode($response->getContent(), true);
    }

    public function test_index_is_forbidden_without_reference_data_manage(): void
    {
        $this->actingAs($this->encoder())
            ->inertiaGet(route('reference-data.index'))
            ->assertForbidden();
    }

    public function test_index_lists_all_13_registry_tables_with_row_counts(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);

        $page = $this->assertInertiaJson(
            $this->actingAs($this->admin())->inertiaGet(route('reference-data.index'))
        );

        $this->assertSame('reference-data/Index', $page['component']);
        $this->assertCount(13, $page['props']['tables']);

        $itemTypes = collect($page['props']['tables'])->firstWhere('key', 'item-types');
        $this->assertNotNull($itemTypes);
        $this->assertSame('Item Types', $itemTypes['label']);
        $this->assertSame(1, $itemTypes['tier']);
        $this->assertSame(2, $itemTypes['row_count']);

        $equipmentLibraries = collect($page['props']['tables'])->firstWhere('key', 'equipment-libraries');
        $this->assertNotNull($equipmentLibraries);
        $this->assertSame(2, $equipmentLibraries['tier']);
    }

    public function test_show_is_forbidden_without_reference_data_manage(): void
    {
        $this->actingAs($this->encoder())
            ->inertiaGet(route('reference-data.show', 'item-types'))
            ->assertForbidden();
    }

    public function test_show_returns_404_for_an_unknown_table(): void
    {
        $this->actingAs($this->admin())
            ->inertiaGet(route('reference-data.show', 'not-a-real-table'))
            ->assertNotFound();
    }

    public function test_show_lists_tier1_rows_and_supports_search(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);
        ItemType::create(['name' => 'Projector', 'sort_order' => 2, 'is_active' => true]);

        $page = $this->assertInertiaJson(
            $this->actingAs($this->admin())->inertiaGet(route('reference-data.show', 'item-types'))
        );

        $this->assertSame('reference-data/Show', $page['component']);
        $this->assertSame('item-types', $page['props']['table']);
        $this->assertSame(1, $page['props']['tier']);
        $this->assertNull($page['props']['types']);
        $this->assertCount(3, $page['props']['rows']['data']);

        $searchPage = $this->assertInertiaJson(
            $this->actingAs($this->admin())
                ->inertiaGet(route('reference-data.show', ['table' => 'item-types', 'search' => 'Desk']))
        );
        $this->assertCount(1, $searchPage['props']['rows']['data']);
        $this->assertSame('Desktop', $searchPage['props']['rows']['data'][0]['name']);
    }

    public function test_show_lists_tier2_rows_and_supports_search_and_type_filter(): void
    {
        EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Piece',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        EquipmentLibrary::create([
            'type' => EquipmentLibraryType::DispositionStatus->value,
            'value' => 'Serviceable',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $page = $this->assertInertiaJson(
            $this->actingAs($this->admin())->inertiaGet(route('reference-data.show', 'equipment-libraries'))
        );

        $this->assertSame(2, $page['props']['tier']);
        $this->assertCount(3, $page['props']['rows']['data']);
        $this->assertNotNull($page['props']['types']);
        $this->assertCount(9, $page['props']['types']); // EquipmentLibraryType has 9 cases.

        $typeFiltered = $this->assertInertiaJson(
            $this->actingAs($this->admin())->inertiaGet(route('reference-data.show', [
                'table' => 'equipment-libraries',
                'type' => EquipmentLibraryType::DispositionStatus->value,
            ]))
        );
        $this->assertCount(1, $typeFiltered['props']['rows']['data']);
        $this->assertSame('Serviceable', $typeFiltered['props']['rows']['data'][0]['value']);

        $searchFiltered = $this->assertInertiaJson(
            $this->actingAs($this->admin())->inertiaGet(route('reference-data.show', [
                'table' => 'equipment-libraries',
                'search' => 'Piece',
            ]))
        );
        $this->assertCount(1, $searchFiltered['props']['rows']['data']);
    }

    public function test_update_is_forbidden_without_reference_data_manage(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->encoder())
            ->patch(route('reference-data.update', ['table' => 'item-types', 'id' => $itemType->id]), [
                'name' => 'Renamed',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertSame('Laptop', $itemType->fresh()->name);
    }

    public function test_update_returns_404_for_an_unknown_table(): void
    {
        $this->actingAs($this->admin())
            ->patch(route('reference-data.update', ['table' => 'not-a-real-table', 'id' => 1]), [
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertNotFound();
    }

    public function test_admin_can_rename_and_edit_a_tier1_row(): void
    {
        $admin = $this->admin();
        $itemType = ItemType::create([
            'name' => 'Laptp',
            'description' => 'Typo in the name',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->patch(
            route('reference-data.update', ['table' => 'item-types', 'id' => $itemType->id]),
            [
                'name' => 'Laptop',
                'description' => 'Corrected.',
                'sort_order' => 5,
                'is_active' => false,
            ]
        );

        $response->assertRedirect(route('reference-data.show', 'item-types'));

        $itemType->refresh();
        $this->assertSame('Laptop', $itemType->name);
        $this->assertSame('Corrected.', $itemType->description);
        $this->assertSame(5, $itemType->sort_order);
        $this->assertFalse($itemType->is_active);

        $log = AuditLog::where('action', 'reference_data.updated')->where('subject_id', $itemType->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('item-types', $log->properties['table']);
        $this->assertSame('Laptp', $log->properties['before']['name']);
        $this->assertSame('Laptop', $log->properties['after']['name']);
    }

    public function test_tier1_update_rejects_a_name_duplicating_another_row_in_the_same_table(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        $desktop = ItemType::create(['name' => 'Desktop', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())->patch(
            route('reference-data.update', ['table' => 'item-types', 'id' => $desktop->id]),
            ['name' => 'Laptop', 'sort_order' => 1, 'is_active' => true]
        )->assertSessionHasErrors('name');

        $this->assertSame('Desktop', $desktop->fresh()->name);
    }

    public function test_tier1_update_allows_renaming_a_row_to_its_own_current_name(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->patch(
            route('reference-data.update', ['table' => 'item-types', 'id' => $itemType->id]),
            ['name' => 'Laptop', 'sort_order' => 3, 'is_active' => true]
        )->assertSessionHasNoErrors();

        $this->assertSame(3, $itemType->fresh()->sort_order);
    }

    public function test_update_validation_rejects_a_missing_sort_order(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->patch(
            route('reference-data.update', ['table' => 'item-types', 'id' => $itemType->id]),
            ['name' => 'Laptop', 'is_active' => true]
        )->assertSessionHasErrors('sort_order');
    }

    public function test_admin_can_edit_a_tier2_row_but_type_and_value_are_ignored_even_when_submitted(): void
    {
        $admin = $this->admin();
        $library = EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->patch(
            route('reference-data.update', ['table' => 'equipment-libraries', 'id' => $library->id]),
            [
                'type' => EquipmentLibraryType::DispositionStatus->value,
                'value' => 'Renamed',
                'name' => 'Should also be ignored — Tier 2 has no name column',
                'description' => 'Clarified.',
                'sort_order' => 2,
                'is_active' => false,
            ]
        );

        $response->assertRedirect(route('reference-data.show', 'equipment-libraries'));

        $library->refresh();
        $this->assertSame(EquipmentLibraryType::UnitOfMeasure, $library->type);
        $this->assertSame('Unit', $library->value);
        $this->assertSame('Clarified.', $library->description);
        $this->assertSame(2, $library->sort_order);
        $this->assertFalse($library->is_active);

        $log = AuditLog::where('action', 'reference_data.updated')->where('subject_id', $library->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('equipment-libraries', $log->properties['table']);
        $this->assertArrayNotHasKey('name', $log->properties['before']);
    }

    public function test_store_is_forbidden_without_reference_data_manage(): void
    {
        $this->actingAs($this->encoder())
            ->post(route('reference-data.store', 'item-types'), ['name' => 'Tablet'])
            ->assertForbidden();

        $this->assertDatabaseMissing('item_types', ['name' => 'Tablet']);
    }

    public function test_admin_can_create_a_tier1_row(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('reference-data.store', 'item-types'), [
            'name' => 'Tablet',
            'description' => 'Handheld touchscreen device.',
        ]);

        $response->assertRedirect(route('reference-data.show', 'item-types'));

        $itemType = ItemType::where('name', 'Tablet')->first();
        $this->assertNotNull($itemType);
        $this->assertSame('Handheld touchscreen device.', $itemType->description);
        $this->assertSame(0, $itemType->sort_order);
        $this->assertTrue($itemType->is_active);

        $log = AuditLog::where('action', 'reference_data.created')->where('subject_id', $itemType->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('item-types', $log->properties['table']);
        $this->assertSame('Tablet', $log->properties['name']);
    }

    public function test_store_rejects_a_tier1_name_duplicating_another_row_in_the_same_table(): void
    {
        ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('reference-data.store', 'item-types'), ['name' => 'Laptop'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ItemType::where('name', 'Laptop')->count());
    }

    public function test_admin_can_create_a_tier2_row_with_a_valid_type(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('reference-data.store', 'equipment-libraries'), [
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Lot',
        ]);

        $response->assertRedirect(route('reference-data.show', 'equipment-libraries'));

        $library = EquipmentLibrary::where('value', 'Lot')->first();
        $this->assertNotNull($library);
        $this->assertSame(EquipmentLibraryType::UnitOfMeasure, $library->type);
        $this->assertTrue($library->is_active);
    }

    public function test_store_rejects_a_tier2_type_that_is_not_a_valid_enum_case(): void
    {
        $this->actingAs($this->admin())
            ->post(route('reference-data.store', 'equipment-libraries'), [
                'type' => 'not-a-real-type',
                'value' => 'Lot',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_rejects_a_tier2_value_duplicating_another_row_of_the_same_type(): void
    {
        EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('reference-data.store', 'equipment-libraries'), [
                'type' => EquipmentLibraryType::UnitOfMeasure->value,
                'value' => 'Unit',
            ])
            ->assertSessionHasErrors('value');
    }

    public function test_store_allows_a_tier2_value_reused_under_a_different_type(): void
    {
        EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Serviceable',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('reference-data.store', 'equipment-libraries'), [
                'type' => EquipmentLibraryType::DispositionStatus->value,
                'value' => 'Serviceable',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, EquipmentLibrary::where('value', 'Serviceable')->count());
    }

    public function test_destroy_is_forbidden_without_reference_data_manage(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->encoder())
            ->delete(route('reference-data.destroy', ['table' => 'item-types', 'id' => $itemType->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('item_types', ['id' => $itemType->id]);
    }

    public function test_admin_can_hard_delete_an_unreferenced_tier1_row(): void
    {
        $admin = $this->admin();
        $itemType = ItemType::create(['name' => 'Fax Machine', 'sort_order' => 0, 'is_active' => true]);

        $response = $this->actingAs($admin)
            ->delete(route('reference-data.destroy', ['table' => 'item-types', 'id' => $itemType->id]));

        $response->assertRedirect(route('reference-data.show', 'item-types'));
        $this->assertDatabaseMissing('item_types', ['id' => $itemType->id]);

        $log = AuditLog::where('action', 'reference_data.deleted')->where('subject_id', $itemType->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('item-types', $log->properties['table']);
    }

    public function test_tier1_delete_is_blocked_by_the_restrict_on_delete_fk_when_still_referenced(): void
    {
        $itemType = ItemType::create(['name' => 'Laptop', 'sort_order' => 0, 'is_active' => true]);
        Equipment::factory()->create(['item_type_id' => $itemType->id]);

        $this->actingAs($this->admin())
            ->delete(route('reference-data.destroy', ['table' => 'item-types', 'id' => $itemType->id]))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('item_types', ['id' => $itemType->id]);
    }

    public function test_isp_provider_delete_is_blocked_when_referenced_only_via_json_survey_columns(): void
    {
        $provider = IspProvider::create(['name' => 'PLDT', 'sort_order' => 0, 'is_active' => true]);
        $office = Office::create(['office_name' => 'Test Elementary School']);
        InternetConnectivitySurvey::create(['office_id' => $office->id, 'available_isps' => [$provider->id]]);

        $this->actingAs($this->admin())
            ->delete(route('reference-data.destroy', ['table' => 'isp-providers', 'id' => $provider->id]))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('isp_providers', ['id' => $provider->id]);
    }

    public function test_isp_provider_delete_succeeds_when_not_referenced_anywhere(): void
    {
        $provider = IspProvider::create(['name' => 'Globe', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->delete(route('reference-data.destroy', ['table' => 'isp-providers', 'id' => $provider->id]))
            ->assertRedirect(route('reference-data.show', 'isp-providers'));

        $this->assertDatabaseMissing('isp_providers', ['id' => $provider->id]);
    }

    public function test_tier2_delete_is_blocked_when_referenced_via_a_string_column(): void
    {
        $library = EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Unit',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        Equipment::factory()->create(['uom' => 'Unit']);

        $this->actingAs($this->admin())
            ->delete(route('reference-data.destroy', ['table' => 'equipment-libraries', 'id' => $library->id]))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('equipment_libraries', ['id' => $library->id]);
    }

    public function test_tier2_delete_is_blocked_when_referenced_via_a_json_array_column(): void
    {
        $library = PersonnelLibrary::create([
            'type' => PersonnelLibraryType::TeachersFundingSource->value,
            'value' => 'SEF',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        Personnel::factory()->create(['fund_source' => [$library->id]]);

        $this->actingAs($this->admin())
            ->delete(route('reference-data.destroy', ['table' => 'personnel-libraries', 'id' => $library->id]))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('personnel_libraries', ['id' => $library->id]);
    }

    public function test_tier2_delete_succeeds_when_not_referenced_anywhere(): void
    {
        $admin = $this->admin();
        $library = EquipmentLibrary::create([
            'type' => EquipmentLibraryType::UnitOfMeasure->value,
            'value' => 'Lot',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->delete(route('reference-data.destroy', ['table' => 'equipment-libraries', 'id' => $library->id]));

        $response->assertRedirect(route('reference-data.show', 'equipment-libraries'));
        $this->assertDatabaseMissing('equipment_libraries', ['id' => $library->id]);

        $log = AuditLog::where('action', 'reference_data.deleted')->where('subject_id', $library->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('equipment-libraries', $log->properties['table']);
    }
}

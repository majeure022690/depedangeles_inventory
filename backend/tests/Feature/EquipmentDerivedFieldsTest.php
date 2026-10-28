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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentDerivedFieldsTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const OFFICE_ICT = 'Office, ICT Equipment, Furniture And Fixtures';

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->editor = $this->userWithPermissions([
            Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit,
        ]);
    }

    private function classification(string $name): int
    {
        return EquipmentClassification::where('name', $name)->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'property_no' => 'AC-2026-IT-LT-000500',
            'item_type_id' => ItemType::where('name', 'Laptop')->valueOrFail('id'),
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'equipment_classification_id' => $this->classification(self::OFFICE_ICT),
            'acquisition_cost' => 30000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'disposition_status' => 'Normal',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function store(array $overrides = []): array
    {
        return $this->actingAs($this->editor)->postJson('/api/equipment', $this->payload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @return array<string, array{0: float|int|null, 1: string}>
     */
    public static function costCategories(): array
    {
        return [
            'just below the threshold' => [49999.99, 'Low-value'],
            'at the threshold' => [50000, 'High-value'],
            'no cost on file' => [null, 'Low-value'],
        ];
    }

    #[DataProvider('costCategories')]
    public function test_category_is_derived_from_cost(float|int|null $cost, string $category): void
    {
        $data = $this->store(['acquisition_cost' => $cost]);

        $this->assertSame($category, $data['category']);
        $this->assertSame(EquipmentCategory::where('name', $category)->valueOrFail('id'), $data['equipment_category_id']);
    }

    private function categoryId(string $name): int
    {
        return EquipmentCategory::where('name', $name)->valueOrFail('id');
    }

    public function test_provided_category_and_under_warranty_are_kept_even_when_they_disagree_with_the_derivation(): void
    {
        $data = $this->store([
            'acquisition_cost' => 100,
            'equipment_category_id' => $this->categoryId('High-value'),
            'under_warranty' => true,
            'end_warranty_date' => null,
        ]);

        $this->assertSame('High-value', $data['category']);
        $this->assertTrue($data['under_warranty']);
        $this->assertNull($data['warranty_active']);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$data['id']}", $this->payload([
            'acquisition_cost' => 90000,
            'equipment_category_id' => $this->categoryId('Low-value'),
            'under_warranty' => false,
            'end_warranty_date' => now()->addYear()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('data.category', 'Low-value')
            ->assertJsonPath('data.under_warranty', false)
            ->assertJsonPath('data.warranty_active', true);
    }

    public function test_blank_category_and_under_warranty_are_derived_on_store(): void
    {
        $data = $this->store([
            'acquisition_cost' => 75000,
            'equipment_category_id' => null,
            'under_warranty' => null,
            'end_warranty_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertSame('High-value', $data['category']);
        $this->assertTrue($data['under_warranty']);
        $this->assertTrue($data['warranty_active']);
    }

    public function test_update_without_the_fields_keeps_stored_values_even_if_cost_changes(): void
    {
        $id = $this->store([
            'acquisition_cost' => 100,
            'equipment_category_id' => $this->categoryId('High-value'),
            'under_warranty' => true,
        ])['id'];

        $this->actingAs($this->editor)->putJson("/api/equipment/{$id}", $this->payload([
            'acquisition_cost' => 200,
            'end_warranty_date' => now()->subYear()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('data.category', 'High-value')
            ->assertJsonPath('data.under_warranty', true)
            ->assertJsonPath('data.warranty_active', false);
    }

    public function test_update_sending_blank_rederives_and_is_audited(): void
    {
        $id = $this->store([
            'acquisition_cost' => 49999.99,
            'under_warranty' => true,
        ])['id'];

        $this->actingAs($this->editor)->putJson("/api/equipment/{$id}", $this->payload([
            'acquisition_cost' => 50000,
            'equipment_category_id' => null,
            'under_warranty' => null,
            'end_warranty_date' => null,
        ]))->assertOk()
            ->assertJsonPath('data.category', 'High-value')
            ->assertJsonPath('data.under_warranty', false);

        $activity = Activity::query()->where('subject_type', Equipment::class)->where('subject_id', $id)->where('event', 'updated')->sole();
        $this->assertSame($this->categoryId('Low-value'), $activity->attribute_changes['old']['equipment_category_id']);
        $this->assertSame($this->categoryId('High-value'), $activity->attribute_changes['attributes']['equipment_category_id']);
        $this->assertTrue($activity->attribute_changes['old']['under_warranty']);
        $this->assertFalse($activity->attribute_changes['attributes']['under_warranty']);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$id}", $this->payload(['acquisition_cost' => null, 'equipment_category_id' => '']))
            ->assertOk()
            ->assertJsonPath('data.category', 'Low-value');
    }

    public function test_resource_returns_the_stored_flag_and_a_computed_warranty_active(): void
    {
        $this->assertFalse($this->store(['property_no' => 'W-1', 'end_warranty_date' => null])['under_warranty']);
        $this->assertFalse($this->store(['property_no' => 'W-2', 'end_warranty_date' => now()->subDay()->toDateString()])['warranty_active']);
        $this->assertTrue($this->store(['property_no' => 'W-3', 'end_warranty_date' => now()->toDateString()])['warranty_active']);

        $future = $this->store(['property_no' => 'W-4', 'end_warranty_date' => now()->addDay()->toDateString()]);
        $this->assertTrue($future['under_warranty']);
        $this->assertTrue($future['warranty_active']);

        Equipment::query()->whereKey($future['id'])->update(['end_warranty_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$future['id']}")
            ->assertOk()
            ->assertJsonPath('data.under_warranty', true)
            ->assertJsonPath('data.warranty_active', false);
    }

    public function test_category_must_be_an_active_category(): void
    {
        $inactive = EquipmentCategory::where('name', 'High-value')->firstOrFail();
        $inactive->update(['is_active' => false]);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->payload(['equipment_category_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment_category_id']);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->payload(['under_warranty' => 'maybe']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['under_warranty']);
    }

    public function test_classification_defaults_fill_blank_codes_without_overwriting_provided_values(): void
    {
        $defaulted = $this->store(['property_no' => 'C-1']);
        $this->assertSame('10605030', $defaulted['gl_sl_code']);
        $this->assertSame('1060503000', $defaulted['uacs']);
        $this->assertSame(5, $defaulted['estimated_useful_life']);

        $overridden = $this->store(['property_no' => 'C-2', 'gl_sl_code' => '999', 'uacs' => '888', 'estimated_useful_life' => 7]);
        $this->assertSame('999', $overridden['gl_sl_code']);
        $this->assertSame('888', $overridden['uacs']);
        $this->assertSame(7, $overridden['estimated_useful_life']);

        $this->actingAs($this->editor)->putJson("/api/equipment/{$overridden['id']}", $this->payload([
            'property_no' => 'C-2', 'gl_sl_code' => null, 'uacs' => '777', 'estimated_useful_life' => 3,
        ]))->assertOk()
            ->assertJsonPath('data.gl_sl_code', '10605030')
            ->assertJsonPath('data.uacs', '777')
            ->assertJsonPath('data.estimated_useful_life', 3);

        $none = $this->store(['property_no' => 'C-3', 'equipment_classification_id' => $this->classification('Machinery and Equipment')]);
        $this->assertNull($none['gl_sl_code']);
        $this->assertNull($none['uacs']);
        $this->assertNull($none['estimated_useful_life']);
    }

    public function test_classification_stays_required(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->payload(['equipment_classification_id' => null]))
            ->assertJsonValidationErrors('equipment_classification_id');
    }

    public function test_uom_and_allotment_class_default_when_blank_and_keep_provided_values(): void
    {
        $laptop = $this->store(['property_no' => 'U-1']);
        $this->assertSame('Piece', $laptop['uom']);
        $this->assertSame('Not Applicable', $laptop['allotment_class']);

        $desktop = $this->store(['property_no' => 'U-2', 'item_type_id' => ItemType::where('name', 'Desktop (package)')->valueOrFail('id'), 'uom' => '']);
        $this->assertSame('Set (bundled)', $desktop['uom']);

        $provided = $this->store(['property_no' => 'U-3', 'uom' => 'Lot', 'allotment_class' => 'Capital Outlay (CO)']);
        $this->assertSame('Lot', $provided['uom']);
        $this->assertSame('Capital Outlay (CO)', $provided['allotment_class']);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->payload(['uom' => 'Bushel', 'allotment_class' => 'Nope']))
            ->assertJsonValidationErrors(['uom', 'allotment_class']);
    }

    public function test_dropped_fields_stay_optional_but_accepted(): void
    {
        $data = $this->store(['old_property_no' => 'OLD-1', 'pm_plan' => 'PPMP-1', 'non_functional' => true]);

        $this->assertSame('OLD-1', $data['old_property_no']);
        $this->assertSame('PPMP-1', $data['pm_plan']);
        $this->assertTrue($data['non_functional']);
    }

    public function test_new_peripherals_get_their_own_category_uom_and_classification_defaults(): void
    {
        $user = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate]);
        $desktop = Equipment::factory()->create([
            'property_no' => 'AC-2016-IT-DST-000009',
            'item_type_id' => ItemType::where('name', 'Desktop (package)')->valueOrFail('id'),
            'equipment_classification_id' => $this->classification(self::OFFICE_ICT),
            'uom' => 'Set (bundled)',
            'acquisition_cost' => 75000,
            'equipment_category_id' => null,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        $this->assertSame('High-value', $desktop->equipmentCategory?->name);

        $add = fn (?float $cost) => $this->actingAs($user)->postJson("/api/equipment/{$desktop->id}/peripherals", [
            'item_type_id' => ItemType::where('name', 'Monitor')->valueOrFail('id'),
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'acquisition_cost' => $cost,
        ])->assertCreated();

        $add(8000)->assertJsonPath('data.category', 'Low-value')
            ->assertJsonPath('data.uom', 'Piece')
            ->assertJsonPath('data.gl_sl_code', '10605030')
            ->assertJsonPath('data.estimated_useful_life', 5);
        $add(60000)->assertJsonPath('data.category', 'High-value');
        $add(null)->assertJsonPath('data.category', 'Low-value');
    }

    public function test_options_ship_classification_defaults_keyed_by_id(): void
    {
        $officeIct = $this->classification(self::OFFICE_ICT);
        $machinery = $this->classification('Machinery and Equipment');

        $this->actingAs($this->editor)->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonPath("data.classification_defaults.{$officeIct}", [
                'default_gl_sl_code' => '10605030',
                'default_uacs' => '1060503000',
                'default_useful_life' => 5,
            ])
            ->assertJsonPath("data.classification_defaults.{$machinery}", [
                'default_gl_sl_code' => null,
                'default_uacs' => null,
                'default_useful_life' => null,
            ]);

        $this->actingAs($this->userWithPermissions([]))->getJson('/api/equipment/options')->assertForbidden();
    }
}

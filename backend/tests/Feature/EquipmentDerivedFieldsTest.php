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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentDerivedFieldsTest extends TestCase
{
    use BuildsEquipmentPayloads, CreatesUsersWithRoles, RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->editor = $this->userWithPermissions([
            Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate,
        ]);
    }

    private function classification(string $name): int
    {
        return EquipmentClassification::where('name', $name)->valueOrFail('id');
    }

    private function categoryId(string $name): int
    {
        return EquipmentCategory::where('name', $name)->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function store(array $overrides = []): array
    {
        return $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function update(int $id, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->putJson("/api/equipment/{$id}", $this->equipmentFields($overrides));
    }

    /**
     * @return array<string, array{0: float|int, 1: string, 2: string}>
     */
    public static function costCategories(): array
    {
        return [
            'zero' => [0, 'Low-value', '10405030'],
            'just below the threshold' => [49999.99, 'Low-value', '10405030'],
            'at the threshold' => [50000.00, 'High-value', '10605030'],
            'above the threshold' => [250000, 'High-value', '10605030'],
        ];
    }

    #[DataProvider('costCategories')]
    public function test_category_and_gl_sl_code_are_derived_from_cost_on_create(float|int $cost, string $category, string $glSlCode): void
    {
        $data = $this->store(['acquisition_cost' => $cost]);

        $this->assertSame($category, $data['category']);
        $this->assertSame($this->categoryId($category), $data['equipment_category_id']);
        $this->assertSame($glSlCode, $data['gl_sl_code']);
    }

    #[DataProvider('costCategories')]
    public function test_a_cost_change_on_update_rederives_both_and_is_audited(float|int $cost, string $category, string $glSlCode): void
    {
        $id = $this->store(['acquisition_cost' => $cost >= 50000 ? 100 : 90000])['id'];

        $this->update($id, ['acquisition_cost' => $cost])
            ->assertOk()
            ->assertJsonPath('data.category', $category)
            ->assertJsonPath('data.gl_sl_code', $glSlCode);

        $activity = Activity::query()->where('subject_type', Equipment::class)->where('subject_id', $id)->where('event', 'updated')->sole();
        $this->assertSame($this->categoryId($category), $activity->attribute_changes['attributes']['equipment_category_id']);
        $this->assertSame($glSlCode, $activity->attribute_changes['attributes']['gl_sl_code']);
    }

    public function test_category_and_gl_sl_code_sent_by_the_client_are_ignored(): void
    {
        $data = $this->store([
            'acquisition_cost' => 100,
            'equipment_category_id' => $this->categoryId('High-value'),
            'gl_sl_code' => '999',
        ]);

        $this->assertSame('Low-value', $data['category']);
        $this->assertSame('10405030', $data['gl_sl_code']);

        $this->update($data['id'], [
            'acquisition_cost' => 100,
            'equipment_category_id' => $this->categoryId('High-value'),
            'gl_sl_code' => '999',
        ])->assertOk()
            ->assertJsonPath('data.category', 'Low-value')
            ->assertJsonPath('data.gl_sl_code', '10405030');
    }

    public function test_an_existing_record_keeps_its_stored_category_and_gl_sl_code_until_its_cost_changes(): void
    {
        $legacy = Equipment::factory()->create($this->equipmentFields([
            'property_no' => 'AC-2019-IT-LPT-000007',
            'acquisition_cost' => 30000,
            'equipment_category_id' => $this->categoryId('High-value'),
            'gl_sl_code' => '10605030',
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]));

        $this->update($legacy->id, ['acquisition_cost' => '30000.00', 'remarks' => 'Checked.'])
            ->assertOk()
            ->assertJsonPath('data.category', 'High-value')
            ->assertJsonPath('data.gl_sl_code', '10605030')
            ->assertJsonPath('data.property_no', 'AC-2019-IT-LPT-000007');

        $activity = Activity::query()->where('subject_id', $legacy->id)->where('event', 'updated')->sole();
        $this->assertArrayNotHasKey('equipment_category_id', $activity->attribute_changes['attributes']);
        $this->assertArrayNotHasKey('gl_sl_code', $activity->attribute_changes['attributes']);

        $this->update($legacy->id, ['acquisition_cost' => 30000.01])
            ->assertOk()
            ->assertJsonPath('data.category', 'Low-value')
            ->assertJsonPath('data.gl_sl_code', '10405030');
    }

    public function test_under_warranty_is_derived_when_blank_and_kept_when_provided(): void
    {
        $derived = $this->store(['under_warranty' => null, 'end_warranty_date' => now()->addMonth()->toDateString()]);
        $this->assertTrue($derived['under_warranty']);
        $this->assertTrue($derived['warranty_active']);

        $provided = $this->store(['under_warranty' => true, 'end_warranty_date' => null]);
        $this->assertTrue($provided['under_warranty']);
        $this->assertNull($provided['warranty_active']);

        $this->update($provided['id'], ['under_warranty' => false, 'end_warranty_date' => now()->addYear()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.under_warranty', false)
            ->assertJsonPath('data.warranty_active', true);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['under_warranty' => 'maybe']))
            ->assertJsonValidationErrors(['under_warranty']);
    }

    public function test_resource_returns_the_stored_flag_and_a_computed_warranty_active(): void
    {
        $this->assertFalse($this->store(['end_warranty_date' => null])['under_warranty']);
        $this->assertFalse($this->store(['end_warranty_date' => now()->subDay()->toDateString()])['warranty_active']);
        $this->assertTrue($this->store(['end_warranty_date' => now()->toDateString()])['warranty_active']);

        $future = $this->store(['end_warranty_date' => now()->addDay()->toDateString()]);
        $this->assertTrue($future['under_warranty']);
        $this->assertTrue($future['warranty_active']);

        Equipment::query()->whereKey($future['id'])->update(['end_warranty_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->editor)->getJson("/api/equipment/{$future['id']}")
            ->assertOk()
            ->assertJsonPath('data.under_warranty', true)
            ->assertJsonPath('data.warranty_active', false);
    }

    public function test_classification_defaults_fill_blank_uacs_and_useful_life_but_not_the_gl_sl_code(): void
    {
        $defaulted = $this->store(['acquisition_cost' => 1000]);
        $this->assertSame('10405030', $defaulted['gl_sl_code']);
        $this->assertSame('1060503000', $defaulted['uacs']);
        $this->assertSame(5, $defaulted['estimated_useful_life']);

        $overridden = $this->store(['uacs' => '888', 'estimated_useful_life' => 7]);
        $this->assertSame('888', $overridden['uacs']);
        $this->assertSame(7, $overridden['estimated_useful_life']);

        $this->update($overridden['id'], ['uacs' => null, 'estimated_useful_life' => 3])
            ->assertOk()
            ->assertJsonPath('data.uacs', '1060503000')
            ->assertJsonPath('data.estimated_useful_life', 3);

        $none = $this->store(['equipment_classification_id' => $this->classification('Machinery and Equipment')]);
        $this->assertSame('10605030', $none['gl_sl_code']);
        $this->assertNull($none['uacs']);
        $this->assertNull($none['estimated_useful_life']);
    }

    public function test_classification_stays_required(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['equipment_classification_id' => null]))
            ->assertJsonValidationErrors('equipment_classification_id');
    }

    public function test_allotment_class_defaults_when_blank_and_lookup_values_are_validated(): void
    {
        $this->assertSame('Not Applicable', $this->store()['allotment_class']);
        $this->assertSame('Capital Outlay (CO)', $this->store(['allotment_class' => 'Capital Outlay (CO)'])['allotment_class']);
        $this->assertSame('Lot', $this->store(['uom' => 'Lot'])['uom']);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['uom' => 'Bushel', 'allotment_class' => 'Nope']))
            ->assertJsonValidationErrors(['uom', 'allotment_class']);
    }

    public function test_optional_fields_are_accepted(): void
    {
        $data = $this->store(['old_property_no' => 'OLD-1', 'pm_plan' => 'PPMP-1', 'non_functional' => true, 'supplier' => 'Acme', 'remarks' => 'Spare.']);

        $this->assertSame('OLD-1', $data['old_property_no']);
        $this->assertSame('PPMP-1', $data['pm_plan']);
        $this->assertTrue($data['non_functional']);
        $this->assertSame('Acme', $data['supplier']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function donorSources(): array
    {
        return array_combine(Equipment::DONOR_SOURCES, array_map(fn (string $source) => [$source], Equipment::DONOR_SOURCES));
    }

    #[DataProvider('donorSources')]
    public function test_donor_is_required_for_donor_sources(string $source): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['source_acquisition' => $source, 'donor' => '']))
            ->assertJsonValidationErrors(['donor']);

        $this->assertSame('Angeles City LGU', $this->store(['source_acquisition' => $source, 'donor' => 'Angeles City LGU'])['donor']);
    }

    public function test_donor_is_stored_as_null_for_other_sources(): void
    {
        $data = $this->store(['source_acquisition' => 'Central Office', 'donor' => 'Someone']);
        $this->assertNull($data['donor']);

        $donated = $this->store(['source_acquisition' => 'DICT', 'donor' => 'DICT Region III']);

        $this->update($donated['id'], ['source_acquisition' => 'School Division Office', 'donor' => 'DICT Region III'])
            ->assertOk()
            ->assertJsonPath('data.donor', null);
    }

    public function test_dcp_package_and_year_are_required_unless_non_dcp(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['non_dcp' => false, 'dcp_package' => null, 'dcp_year' => null]))
            ->assertJsonValidationErrors(['dcp_package', 'dcp_year']);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['non_dcp' => false, 'dcp_package' => 'Not a package', 'dcp_year' => 24]))
            ->assertJsonValidationErrors(['dcp_package', 'dcp_year']);

        $dcp = $this->store(['non_dcp' => false, 'dcp_package' => 'Laptop for Teaching', 'dcp_year' => 2024]);
        $this->assertFalse($dcp['non_dcp']);
        $this->assertSame('Laptop for Teaching', $dcp['dcp_package']);
        $this->assertSame(2024, $dcp['dcp_year']);

        $this->update($dcp['id'], ['non_dcp' => true, 'dcp_package' => 'Laptop for Teaching', 'dcp_year' => 2024])
            ->assertOk()
            ->assertJsonPath('data.dcp_package', null)
            ->assertJsonPath('data.dcp_year', null);
    }

    public function test_non_dcp_ignores_any_package_sent(): void
    {
        $data = $this->store(['non_dcp' => true, 'dcp_package' => 'Not a package', 'dcp_year' => 'abc']);

        $this->assertTrue($data['non_dcp']);
        $this->assertNull($data['dcp_package']);
        $this->assertNull($data['dcp_year']);
    }

    public function test_new_peripherals_get_their_own_category_gl_sl_code_and_uom(): void
    {
        $desktop = Equipment::factory()->create([
            'property_no' => 'AC-2016-IT-DST-000009',
            'item_type_id' => ItemType::where('name', 'Desktop (package)')->valueOrFail('id'),
            'equipment_classification_id' => $this->classification('Office, ICT Equipment, Furniture And Fixtures'),
            'uom' => 'Set (bundled)',
            'acquisition_cost' => 75000,
            'equipment_category_id' => null,
            'gl_sl_code' => null,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        $this->assertSame('High-value', $desktop->equipmentCategory?->name);
        $this->assertSame('10605030', $desktop->gl_sl_code);

        $add = fn (?float $cost) => $this->actingAs($this->editor)->postJson("/api/equipment/{$desktop->id}/peripherals", [
            'item_type_id' => ItemType::where('name', 'Monitor')->valueOrFail('id'),
            'brand_id' => Brand::where('name', 'Dell')->valueOrFail('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'acquisition_cost' => $cost,
        ])->assertCreated();

        $add(8000)->assertJsonPath('data.category', 'Low-value')
            ->assertJsonPath('data.uom', 'Piece')
            ->assertJsonPath('data.gl_sl_code', '10405030')
            ->assertJsonPath('data.estimated_useful_life', 5);
        $add(60000)->assertJsonPath('data.category', 'High-value')->assertJsonPath('data.gl_sl_code', '10605030');
        $add(null)->assertJsonPath('data.category', 'Low-value');
    }

    public function test_options_ship_classification_defaults_keyed_by_id(): void
    {
        $officeIct = $this->classification('Office, ICT Equipment, Furniture And Fixtures');
        $machinery = $this->classification('Machinery and Equipment');

        $this->actingAs($this->editor)->getJson('/api/equipment/options')
            ->assertOk()
            ->assertJsonPath("data.classification_defaults.{$officeIct}", [
                'default_uacs' => '1060503000',
                'default_useful_life' => 5,
            ])
            ->assertJsonPath("data.classification_defaults.{$machinery}", [
                'default_uacs' => null,
                'default_useful_life' => null,
            ]);

        $this->actingAs($this->userWithPermissions([]))->getJson('/api/equipment/options')->assertForbidden();
    }
}

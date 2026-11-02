<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class PropertyItemControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Personnel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->office = Office::factory()->create(['office_name' => 'Sample ES']);
        $this->officer = Personnel::factory()->create(['office_id' => $this->office->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'current_accountable_officer_id' => $this->officer->id,
            'current_end_user_id' => null,
            'item_type_id' => ItemType::where('name', 'Laptop')->valueOrFail('id'),
            'brand_id' => Brand::firstOrCreate(['name' => 'Dell'], ['is_active' => true])->id,
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'disposition_status' => 'Normal',
            'non_functional' => false,
            'acquisition_cost' => 10000,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, string|int>  $filters
     * @return array<string, mixed>
     */
    private function summary(array $filters = [], ?DataScope $scope = null, ?int $officeId = null): array
    {
        $user = $this->userWithPermissions([Permission::PropertyItemsView], ['office_id' => $officeId], $scope ?? DataScope::All);

        return $this->actingAs($user)->getJson('/api/property-items/summary?'.http_build_query($filters))->assertOk()->json('data');
    }

    public function test_guests_and_users_without_permission_are_rejected(): void
    {
        foreach (['summary', 'equipment', 'options'] as $endpoint) {
            $this->getJson("/api/property-items/{$endpoint}")->assertUnauthorized();
        }

        foreach (['summary', 'equipment', 'options'] as $endpoint) {
            $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson("/api/property-items/{$endpoint}")->assertForbidden();
        }
    }

    public function test_summary_totals_items_on_hand_and_their_cost_per_office(): void
    {
        $this->item(['acquisition_cost' => 30000]);
        $this->item(['acquisition_cost' => 20000]);
        $this->item(['acquisition_cost' => 5000, 'disposition_status' => 'Lost']);
        $this->item(['acquisition_cost' => 99999, 'disposition_status' => 'Transferred']);
        $other = Office::factory()->create(['office_name' => 'Another HS']);
        $this->item(['current_accountable_officer_id' => null, 'current_end_user_id' => Personnel::factory()->create(['office_id' => $other->id])->id, 'acquisition_cost' => 1500.5]);

        $data = $this->summary();

        $this->assertSame([
            ['office_id' => $other->id, 'office_name' => 'Another HS', 'on_hand' => 1, 'missing' => 0, 'total_cost' => 1500.5],
            ['office_id' => $this->office->id, 'office_name' => 'Sample ES', 'on_hand' => 2, 'missing' => 1, 'total_cost' => 50000],
        ], $data['offices']);
        $this->assertSame(['on_hand' => 3, 'missing' => 1, 'total_cost' => 51500.5], $data['totals']);
    }

    public function test_summary_filters_narrow_the_totals(): void
    {
        $desktop = ItemType::where('name', 'Desktop (package)')->valueOrFail('id');
        $acer = Brand::firstOrCreate(['name' => 'Acer'], ['is_active' => true])->id;
        $unserviceable = EquipmentCondition::where('name', 'Unserviceable')->valueOrFail('id');
        $otherOfficer = Personnel::factory()->create(['office_id' => $this->office->id]);
        $receiver = Personnel::factory()->create(['office_id' => $this->office->id]);

        $match = $this->item(['item_type_id' => $desktop, 'brand_id' => $acer, 'non_functional' => true, 'equipment_condition_id' => $unserviceable, 'disposition_status' => 'For Disposal', 'estimated_useful_life' => 5]);
        $match->transactions()->create(['transaction_type' => 'Beginning Inventory']);
        $match->transactions()->create(['transaction_type' => 'Issuance/Transfer', 'received_by_id' => $receiver->id]);
        $earlier = $this->item(['estimated_useful_life' => 7]);
        $earlier->transactions()->create(['transaction_type' => 'Issuance/Transfer', 'received_by_id' => $receiver->id]);
        $earlier->transactions()->create(['transaction_type' => 'Return']);
        $this->item(['current_accountable_officer_id' => $otherOfficer->id, 'estimated_useful_life' => null]);

        $onHand = fn (array $filters) => $this->summary($filters)['totals']['on_hand'];

        $this->assertSame(3, $onHand([]));
        $this->assertSame(1, $onHand(['item_type_id' => $desktop]));
        $this->assertSame(1, $onHand(['brand_id' => $acer]));
        $this->assertSame(1, $onHand(['functional' => 'non_functional']));
        $this->assertSame(2, $onHand(['functional' => 'functional']));
        $this->assertSame(1, $onHand(['equipment_condition_id' => $unserviceable]));
        $this->assertSame(1, $onHand(['disposition_status' => 'For Disposal']));
        $this->assertSame(1, $onHand(['estimated_useful_life' => 7]));
        $this->assertSame(1, $onHand(['accountable_officer_id' => $otherOfficer->id]));
        $this->assertSame(1, $onHand(['transaction_type' => 'Issuance/Transfer']));
        $this->assertSame(1, $onHand(['received_by_id' => $receiver->id]));
        $this->assertSame(1, $onHand(['transaction_type' => 'Return']));
    }

    public function test_school_scoped_users_only_see_their_own_office(): void
    {
        $this->item();
        $other = Office::factory()->create();
        $this->item(['current_accountable_officer_id' => Personnel::factory()->create(['office_id' => $other->id])->id]);

        $data = $this->summary([], DataScope::School, $this->office->id);

        $this->assertSame([$this->office->id], array_column($data['offices'], 'office_id'));
    }

    public function test_equipment_lists_the_items_on_hand_behind_an_office_row(): void
    {
        $counted = $this->item(['acquisition_cost' => 30000, 'property_no' => 'SE-001']);
        $this->item(['disposition_status' => 'Lost']);
        $this->item(['disposition_status' => 'Transferred']);
        $this->item(['current_accountable_officer_id' => Personnel::factory()->create(['office_id' => Office::factory()->create()->id])->id]);

        $response = $this->actingAs($this->userWithPermissions([Permission::PropertyItemsView]))
            ->getJson("/api/property-items/equipment?office_id={$this->office->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $counted->id)
            ->assertJsonPath('data.0.property_no', 'SE-001')
            ->assertJsonPath('data.0.office', 'Sample ES')
            ->assertJsonPath('data.0.semi_expendable', true);

        $this->assertArrayNotHasKey('serial_number', $response->json('data.0'));
    }

    public function test_options_list_filters_from_visible_equipment(): void
    {
        $receiver = Personnel::factory()->create(['office_id' => $this->office->id]);
        $this->item(['estimated_useful_life' => 5])->transactions()->create(['transaction_type' => 'Issuance/Transfer', 'received_by_id' => $receiver->id]);

        $response = $this->actingAs($this->userWithPermissions([Permission::PropertyItemsView]))->getJson('/api/property-items/options')->assertOk();

        $this->assertSame([$this->officer->id], array_column($response->json('data.accountable_officer'), 'value'));
        $this->assertSame([$receiver->id], array_column($response->json('data.received_by'), 'value'));
        $this->assertSame([['value' => 5, 'label' => '5 years']], $response->json('data.estimated_useful_life'));
        foreach (['office', 'item_type', 'brand', 'equipment_condition', 'disposition_status', 'transaction_type'] as $key) {
            $this->assertNotEmpty($response->json("data.{$key}"), $key);
        }
    }
}

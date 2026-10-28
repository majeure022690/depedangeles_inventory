<?php

namespace Tests\Feature;

use App\Enums\DashboardEquipmentGroup;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\ItemType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const SECTIONS = [
        'ppe' => Permission::DashboardPpe,
        'semi_expendable' => Permission::DashboardSemiExpendable,
        'equipment_overview' => Permission::DashboardEquipmentOverview,
        'equipment_distribution' => Permission::DashboardEquipmentDistribution,
        'internet_connectivity' => Permission::DashboardInternetConnectivity,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function viewer(): User
    {
        return $this->userWithPermissions(array_values(self::SECTIONS));
    }

    private function equipment(string $itemType, int $count = 1, ?float $cost = 1000): void
    {
        Equipment::factory()->count($count)->create([
            'item_type_id' => ItemType::where('name', $itemType)->valueOrFail('id'),
            'acquisition_cost' => $cost,
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
    }

    private function summary(?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->viewer())->getJson('/api/dashboard/summary')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function group(TestResponse $response, string $key): array
    {
        $group = collect($response->json('data.equipment_overview.groups'))->firstWhere('key', $key);
        $group['breakdown'] = array_map(fn (array $row) => ['label' => $row['label'], 'count' => $row['count']], $group['breakdown']);

        return $group;
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_holder_of_only_unrelated_permissions_gets_every_section_omitted(): void
    {
        $response = $this->summary($this->userWithPermissions([Permission::EquipmentView, Permission::IspAccountsView]));

        $this->assertSame([], $response->json('data'));
        $this->assertDatabaseMissing('activity_log', ['event' => 'authorization.denied']);
    }

    public function test_each_section_is_present_only_with_its_own_permission(): void
    {
        foreach (self::SECTIONS as $key => $permission) {
            $response = $this->summary($this->userWithPermissions([$permission]));

            $this->assertSame([$key], array_keys($response->json('data')), "Only {$key} expected");
        }

        $this->assertEqualsCanonicalizing(array_keys(self::SECTIONS), array_keys($this->summary()->json('data')));
    }

    public function test_ppe_and_semi_expendable_split_at_50000_and_exclude_unknown_costs(): void
    {
        $this->equipment('Laptop', cost: 50000);
        $this->equipment('Laptop', cost: 75000.50);
        $this->equipment('Laptop', cost: 49999.99);
        $this->equipment('Laptop', cost: 100);
        $this->equipment('Laptop', cost: null);
        Equipment::factory()->create(['acquisition_cost' => 900000, 'current_accountable_officer_id' => null, 'current_end_user_id' => null])->delete();

        $this->summary()
            ->assertJsonPath('data.ppe', ['total_value' => 125000.5, 'item_count' => 2])
            ->assertJsonPath('data.semi_expendable', ['total_value' => 50099.99, 'item_count' => 2]);
    }

    public function test_empty_inventory_reports_zeroes_for_every_section(): void
    {
        $response = $this->summary()
            ->assertJsonPath('data.ppe', ['total_value' => 0, 'item_count' => 0])
            ->assertJsonPath('data.equipment_overview.other.count', 0)
            ->assertJsonPath('data.equipment_distribution.total', 0)
            ->assertJsonPath('data.internet_connectivity.total_accounts', 0)
            ->assertJsonPath('data.internet_connectivity.total_cost', 0)
            ->assertJsonPath('data.internet_connectivity.by_provider', []);

        $this->assertSame(
            array_map(fn (DashboardEquipmentGroup $group) => $group->value, DashboardEquipmentGroup::cases()),
            collect($response->json('data.equipment_overview.groups'))->pluck('key')->all(),
        );
        $this->assertSame([0], collect($response->json('data.equipment_distribution.slices'))->pluck('percentage')->unique()->values()->all());
    }

    public function test_equipment_overview_counts_groups_breakdowns_and_other(): void
    {
        $this->equipment('Desktop (package)', 2);
        $this->equipment('CPU');
        $this->equipment('Monitor', 4);
        $this->equipment('Keyboard', 2);
        $this->equipment('Mouse');
        $this->equipment('Laptop', 3);
        $this->equipment('Tablet');
        $this->equipment('2-in-1 Tablet', 2);
        $this->equipment('Smart TV');
        $this->equipment('Interactive Whiteboards / Smartboards');
        $this->equipment('Thin Client Server (Set)', 2);

        $response = $this->summary();

        $this->assertSame([
            'key' => 'desktop_computer',
            'label' => 'Desktop Computer',
            'count' => 3,
            'breakdown' => [
                ['label' => 'System Unit', 'count' => 3],
                ['label' => 'Monitor', 'count' => 4],
                ['label' => 'Keyboard', 'count' => 2],
                ['label' => 'Mouse', 'count' => 1],
            ],
        ], $this->group($response, 'desktop_computer'));
        $this->assertSame(['key' => 'laptop', 'label' => 'Laptop', 'count' => 3, 'breakdown' => []], $this->group($response, 'laptop'));
        $this->assertSame([['label' => 'Tablet', 'count' => 1], ['label' => '2-in-1 Tablet', 'count' => 2]], $this->group($response, 'tablet')['breakdown']);
        $this->assertSame(3, $this->group($response, 'tablet')['count']);
        $this->assertSame([['label' => 'Standard TV', 'count' => 0], ['label' => 'Smart TV', 'count' => 1]], $this->group($response, 'tv')['breakdown']);
        $this->assertSame([['label' => 'Projector', 'count' => 0], ['label' => 'Smartboard', 'count' => 1]], $this->group($response, 'projector_smartboard')['breakdown']);
        $this->assertSame(['key' => 'copier', 'label' => 'Copier / Xerox Machine', 'count' => 0, 'breakdown' => []], $this->group($response, 'copier'));
        $response->assertJsonPath('data.equipment_overview.other.count', 2);
    }

    public function test_breakdown_rows_carry_their_item_type_ids(): void
    {
        $id = fn (string $name) => ItemType::where('name', $name)->valueOrFail('id');
        $breakdown = collect($this->summary()->json('data.equipment_overview.groups'))->firstWhere('key', 'desktop_computer')['breakdown'];

        $this->assertEqualsCanonicalizing([$id('Desktop (package)'), $id('CPU')], $breakdown[0]['item_type_ids']);
        $this->assertSame([$id('Monitor')], $breakdown[1]['item_type_ids']);
    }

    public function test_equipment_distribution_slices_headline_counts_with_percentages(): void
    {
        $this->equipment('Desktop (package)');
        $this->equipment('Monitor', 5);
        $this->equipment('Laptop', 4);
        $this->equipment('Printer');
        $this->equipment('Thin Client Server (Set)');

        $response = $this->summary()->assertJsonPath('data.equipment_distribution.total', 7);
        $slices = collect($response->json('data.equipment_distribution.slices'))->keyBy('key');

        $this->assertCount(11, $slices);
        $this->assertSame(['key' => 'desktop_computer', 'label' => 'Desktop Computer', 'count' => 1, 'percentage' => 14.3], $slices['desktop_computer']);
        $this->assertSame(57.1, $slices['laptop']['percentage']);
        $this->assertSame(['key' => 'other', 'label' => 'Other equipment', 'count' => 1, 'percentage' => 14.3], $slices['other']);
        $this->assertSame('other', $slices->keys()->last());
    }

    public function test_internet_connectivity_totals_and_breakdowns(): void
    {
        $globe = IspProvider::where('name', 'GLOBE')->valueOrFail('id');
        $pldt = IspProvider::where('name', 'PLDT')->valueOrFail('id');

        IspAccount::factory()->create(['isp_provider_id' => $globe, 'isp_connection_type' => 'Fiber', 'mode_of_acquisition' => 'DepEd Purchase', 'inactive_contract' => false, 'cost_per_month' => 1500]);
        IspAccount::factory()->create(['isp_provider_id' => $globe, 'isp_connection_type' => 'Fiber', 'mode_of_acquisition' => 'DepEd Purchase', 'inactive_contract' => false, 'cost_per_month' => 2500.25]);
        IspAccount::factory()->create(['isp_provider_id' => $pldt, 'isp_connection_type' => 'DSL', 'mode_of_acquisition' => 'DepEd Purchase', 'inactive_contract' => true, 'cost_per_month' => 99999]);
        IspAccount::factory()->create(['isp_provider_id' => $pldt, 'isp_connection_type' => 'Fiber', 'mode_of_acquisition' => 'Donation', 'inactive_contract' => false, 'cost_per_month' => 88888]);
        IspAccount::factory()->create(['isp_provider_id' => $pldt, 'cost_per_month' => 77777])->delete();

        $this->summary()->assertJsonPath('data.internet_connectivity', [
            'total_accounts' => 4,
            'total_cost' => 4000.25,
            'by_provider' => [
                ['name' => 'GLOBE', 'count' => 2, 'percentage' => 50],
                ['name' => 'PLDT', 'count' => 2, 'percentage' => 50],
            ],
            'by_connection_type' => [
                ['value' => 'Fiber', 'count' => 3, 'percentage' => 75],
                ['value' => 'DSL', 'count' => 1, 'percentage' => 25],
            ],
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\ItemType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentPropertyNumberTest extends TestCase
{
    use BuildsEquipmentPayloads, CreatesUsersWithRoles, RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->editor = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit]);
    }

    private function itemType(string $name): int
    {
        return ItemType::where('name', $name)->valueOrFail('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storedNumber(array $overrides = []): ?string
    {
        return $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload($overrides))
            ->assertCreated()
            ->json('data.property_no');
    }

    private function existing(string $propertyNo, string $itemType = 'Laptop'): Equipment
    {
        return Equipment::factory()->create([
            'property_no' => $propertyNo,
            'item_type_id' => $this->itemType($itemType),
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
    }

    public function test_the_first_number_of_a_series_uses_the_acquisition_year_and_item_type_abbreviation(): void
    {
        $this->assertSame('AC-2024-IT-LPT-000001', $this->storedNumber(['received_date' => '2024-11-30']));
        $this->assertSame('AC-2025-IT-LPT-000002', $this->storedNumber(['received_date' => '2025-01-02']));
        $this->assertSame('AC-2025-IT-PRT-000001', $this->storedNumber(['received_date' => '2025-01-02', 'item_type_id' => $this->itemType('Printer')]));
    }

    public function test_the_series_continues_across_years_and_never_resets(): void
    {
        $this->existing('AC-2016-IT-LPT-000001');
        $this->existing('AC-2024-IT-LPT-000072');
        $this->existing('AC-2020-IT-LPT-000040');

        $this->assertSame('AC-2016-IT-LPT-000073', $this->storedNumber(['received_date' => '2016-06-01']));
        $this->assertSame('AC-2026-IT-LPT-000074', $this->storedNumber(['received_date' => '2026-06-01']));
    }

    public function test_soft_deleted_rows_still_hold_their_number(): void
    {
        $this->existing('AC-2023-IT-LPT-000090')->delete();

        $this->assertSame('AC-2026-IT-LPT-000091', $this->storedNumber());
    }

    public function test_bundle_suffixes_other_series_and_free_text_numbers_are_ignored(): void
    {
        $this->existing('AC-2022-IT-LPT-000010');
        $this->existing('AC-2022-IT-LPT-000500-001', 'Monitor');
        $this->existing('AC-2022-IT-LPT-000600-1', 'Monitor');
        $this->existing('AC-2022-IT-LPTX-000700');
        $this->existing('AC-22-IT-LPT-000800');
        $this->existing('AC-2022-IT-LPT-00090');
        $this->existing('XAC-2022-IT-LPT-000900');
        $this->existing('AC-2022-IT-lpt-000950');
        $this->existing('AC-2022-IT-DST-000999', 'Desktop (package)');
        $this->existing('PN-0001');

        $this->assertSame('AC-2026-IT-LPT-000011', $this->storedNumber());
    }

    public function test_a_full_series_is_reported_instead_of_growing_a_seventh_digit(): void
    {
        $this->existing('AC-2020-IT-LPT-999998');
        $this->assertSame('AC-2026-IT-LPT-999999', $this->storedNumber());

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['property_no']);

        $this->assertSame(2, Equipment::count());
        $this->assertFalse(Equipment::withTrashed()->where('property_no', 'like', '%-1000000')->exists());
    }

    public function test_property_no_is_not_accepted_on_create(): void
    {
        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['property_no' => 'AC-2026-IT-LPT-123456']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['property_no']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_an_item_type_without_an_abbreviation_cannot_be_numbered(): void
    {
        $itemType = ItemType::create(['name' => 'Hologram Projector', 'is_active' => true]);

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload(['item_type_id' => $itemType->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_type_id']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_a_unique_collision_on_save_is_retried_and_then_reported(): void
    {
        $attempts = [];
        Equipment::creating(function (Equipment $equipment) use (&$attempts) {
            $attempts[] = $equipment->property_no;

            if (count($attempts) === 1) {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \RuntimeException('taken'));
            }
        });

        $this->assertSame('AC-2026-IT-LPT-000002', $this->storedNumber());
        $this->assertSame(['AC-2026-IT-LPT-000001', 'AC-2026-IT-LPT-000002'], $attempts);

        Equipment::creating(fn () => throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \RuntimeException('taken')));

        $this->actingAs($this->editor)->postJson('/api/equipment', $this->newEquipmentPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['property_no']);

        $this->assertSame(1, Equipment::count());
    }

    public function test_the_property_number_cannot_be_edited_and_blank_ones_stay_blank(): void
    {
        $numbered = $this->existing('AC-2019-IT-LPT-000005');
        $blank = $this->existing('PN-TEMP');
        $blank->update(['property_no' => null]);

        foreach ([$numbered, $blank] as $equipment) {
            foreach (['AC-2019-IT-LPT-000099', (string) $numbered->property_no] as $attempt) {
                $this->actingAs($this->editor)->putJson("/api/equipment/{$equipment->id}", $this->equipmentFields(['property_no' => $attempt]))
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['property_no' => 'cannot be changed']);
            }
        }

        $this->actingAs($this->editor)->putJson("/api/equipment/{$numbered->id}", $this->equipmentFields())
            ->assertOk()
            ->assertJsonPath('data.property_no', 'AC-2019-IT-LPT-000005');
        $this->actingAs($this->editor)->putJson("/api/equipment/{$blank->id}", $this->equipmentFields(['property_no' => null]))
            ->assertOk()
            ->assertJsonPath('data.property_no', null);
    }
}

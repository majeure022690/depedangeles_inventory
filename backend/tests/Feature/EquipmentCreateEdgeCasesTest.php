<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsEquipmentPayloads;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentCreateEdgeCasesTest extends TestCase
{
    use BuildsEquipmentPayloads, CreatesUsersWithRoles, RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->creator = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit]);
    }

    public function test_accountability_cannot_smuggle_recorder_or_equipment_columns(): void
    {
        $other = Equipment::factory()->create(['current_accountable_officer_id' => null, 'current_end_user_id' => null]);
        $someoneElse = User::factory()->create();

        $id = $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload([
            'accountability' => [
                ...$this->accountabilityFields(),
                'recorded_by_user_id' => $someoneElse->id,
                'equipment_id' => $other->id,
                'id' => 999999,
            ],
        ]))->assertCreated()->json('data.id');

        $transaction = EquipmentTransaction::sole();
        $this->assertSame($id, $transaction->equipment_id);
        $this->assertSame($this->creator->id, $transaction->recorded_by_user_id);
        $this->assertNotSame(999999, $transaction->id);
        $this->assertSame(0, $other->transactions()->count());
    }

    public function test_a_failed_initial_transaction_rolls_back_the_record_and_frees_its_number(): void
    {
        $fail = true;
        EquipmentTransaction::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('boom');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload());
            $this->fail('Expected the create to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Equipment::withTrashed()->count());

        $fail = false;
        $this->withExceptionHandling();

        $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload())
            ->assertCreated()
            ->assertJsonPath('data.property_no', 'AC-2026-IT-LPT-000001');
    }

    public function test_accountability_must_be_an_object_and_every_holder_must_exist(): void
    {
        $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload(['accountability' => 'Beginning Inventory']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accountability', 'accountability.accountable_officer_id']);

        $trashed = Personnel::factory()->create();
        $trashed->delete();

        $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload([
            'accountability' => $this->accountabilityFields(null, ['end_user_id' => $trashed->id]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['accountability.end_user_id']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_a_cost_with_more_than_two_decimals_is_rejected_rather_than_rounded(): void
    {
        $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload(['acquisition_cost' => '49999.999']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['acquisition_cost']);

        $this->assertSame(0, Equipment::count());
    }

    public function test_editing_a_legacy_record_needs_the_new_required_fields_and_changes_nothing_until_valid(): void
    {
        $legacy = Equipment::factory()->create([
            ...$this->equipmentFields(),
            'property_no' => 'AC-2018-IT-LPT-000004',
            'used_by' => null,
            'model' => null,
            'gl_sl_code' => '99999999',
            'current_accountable_officer_id' => null,
            'current_end_user_id' => null,
        ]);
        $before = $legacy->fresh()->getAttributes();

        $this->actingAs($this->creator)->putJson("/api/equipment/{$legacy->id}", [
            ...$this->equipmentFields(['used_by' => null, 'model' => null, 'acquisition_cost' => 1]),
            'property_no' => 'AC-2018-IT-LPT-000004',
        ])->assertUnprocessable()->assertJsonValidationErrors(['used_by', 'model']);

        $this->assertEquals($before, $legacy->fresh()->getAttributes());
    }

    public function test_put_ignores_an_accountability_object(): void
    {
        $holder = Personnel::factory()->create();
        $id = $this->actingAs($this->creator)->postJson('/api/equipment', $this->newEquipmentPayload([], $holder))->json('data.id');
        $newcomer = Personnel::factory()->create();

        $this->actingAs($this->creator)->putJson("/api/equipment/{$id}", [
            ...$this->equipmentFields(),
            'accountability' => $this->accountabilityFields($newcomer),
        ])->assertOk();

        $this->assertSame($holder->id, Equipment::findOrFail($id)->current_accountable_officer_id);
        $this->assertSame(1, EquipmentTransaction::count());
    }
}

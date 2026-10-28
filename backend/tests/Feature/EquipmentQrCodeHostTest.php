<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentQrCodeHostTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const CONFIGURED_URL = 'https://dictis.example.gov.ph';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->user = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentCreate, Permission::EquipmentEdit]);
        config(['app.url' => self::CONFIGURED_URL.'/']);
    }

    private function payload(string $propertyNo): array
    {
        return [
            'property_no' => $propertyNo,
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ];
    }

    private function assertQrOnConfiguredHost(Equipment $equipment): void
    {
        $this->assertSame(self::CONFIGURED_URL."/equipment/{$equipment->id}/edit", $equipment->fresh()?->qr_code);
    }

    public function test_create_with_a_spoofed_host_header_stores_the_configured_host(): void
    {
        $id = $this->actingAs($this->user)
            ->withHeader('Host', 'evil.example')
            ->postJson('http://evil.example/api/equipment', $this->payload('QR-HOST-1'))
            ->assertCreated()
            ->json('data.id');

        $this->assertQrOnConfiguredHost(Equipment::findOrFail($id));
    }

    public function test_create_with_a_forwarded_host_behind_a_trusted_proxy_stores_the_configured_host(): void
    {
        TrustProxies::at('*');

        $id = $this->actingAs($this->user)
            ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http'])
            ->postJson('/api/equipment', $this->payload('QR-HOST-2'))
            ->assertCreated()
            ->json('data.id');

        $this->assertQrOnConfiguredHost(Equipment::findOrFail($id));
    }

    public function test_update_backfill_with_a_spoofed_host_header_stores_the_configured_host(): void
    {
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => null]);

        $this->actingAs($this->user)
            ->withHeader('Host', 'evil.example')
            ->putJson("http://evil.example/api/equipment/{$equipment->id}", $this->payload('QR-HOST-3'))
            ->assertOk();

        $this->assertQrOnConfiguredHost($equipment);
    }

    public function test_update_backfill_with_a_forwarded_host_behind_a_trusted_proxy_stores_the_configured_host(): void
    {
        TrustProxies::at('*');
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => null]);

        $this->actingAs($this->user)
            ->withHeader('X-Forwarded-Host', 'evil.example')
            ->putJson("/api/equipment/{$equipment->id}", $this->payload('QR-HOST-4'))
            ->assertOk();

        $this->assertQrOnConfiguredHost($equipment);
    }
}

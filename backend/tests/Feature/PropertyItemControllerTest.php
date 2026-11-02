<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\PropertyItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class PropertyItemControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const ALL = [Permission::PropertyItemsView, Permission::PropertyItemsCreate, Permission::PropertyItemsEdit, Permission::PropertyItemsDelete];

    private Office $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->office = Office::factory()->create(['office_name' => 'Sample ES']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'office_id' => $this->office->id,
            'category' => 'it_equipment',
            'article' => 'Mouse',
            'description' => 'USB optical',
            'unit_of_measure' => 'pc',
            'unit_value' => 350,
            'quantity' => 40,
            'on_hand_count' => 38,
            'source' => 'lgu',
            'condition' => 'functional',
            'with_are' => true,
            ...$overrides,
        ];
    }

    public function test_guests_and_users_without_permission_are_rejected(): void
    {
        $this->getJson('/api/property-items')->assertUnauthorized();
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson('/api/property-items')->assertForbidden();
    }

    public function test_full_crud_is_audited(): void
    {
        $user = $this->userWithPermissions(self::ALL);
        $teacher = Personnel::factory()->create(['office_id' => $this->office->id]);

        $id = $this->actingAs($user)->postJson('/api/property-items', $this->payload(['end_user_id' => $teacher->id]))
            ->assertCreated()
            ->assertJsonPath('data.total_value', 14000)
            ->assertJsonPath('data.category_label', 'IT Equipment')
            ->json('data.id');

        $this->assertSame($user->id, PropertyItem::findOrFail($id)->created_by_user_id);
        $this->actingAs($user)->getJson("/api/property-items/{$id}")->assertOk()->assertJsonPath('data.end_user_id', $teacher->id);
        $this->actingAs($user)->putJson("/api/property-items/{$id}", $this->payload(['quantity' => 41]))->assertOk()->assertJsonPath('data.quantity', 41);
        $this->actingAs($user)->getJson('/api/property-items?category=it_equipment&search=Mouse')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->deleteJson("/api/property-items/{$id}")->assertNoContent();

        $this->assertSoftDeleted('property_items', ['id' => $id]);
        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertDatabaseHas('activity_log', ['description' => "property_item.{$event}", 'subject_id' => $id]);
        }
    }

    public function test_validation_requires_core_fields_and_personnel_from_the_same_office(): void
    {
        $user = $this->userWithPermissions(self::ALL);
        $outsider = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);

        $this->actingAs($user)->postJson('/api/property-items', ['category' => 'nope', 'quantity' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id', 'category', 'article', 'quantity', 'on_hand_count']);

        $this->actingAs($user)->postJson('/api/property-items', $this->payload(['end_user_id' => $outsider->id, 'category' => 'books']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_user_id', 'category']);
    }

    public function test_school_scoped_users_only_reach_their_own_office(): void
    {
        $other = Office::factory()->create();
        $mine = PropertyItem::factory()->create(['office_id' => $this->office->id]);
        $theirs = PropertyItem::factory()->create(['office_id' => $other->id]);
        $user = $this->userWithPermissions(self::ALL, ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($user)->getJson('/api/property-items')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->actingAs($user)->getJson("/api/property-items/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/property-items/{$theirs->id}")->assertNotFound();

        $created = $this->actingAs($user)->postJson('/api/property-items', $this->payload(['office_id' => null]))->assertCreated()->json('data.office_id');
        $this->assertSame($this->office->id, $created);

        $this->actingAs($user)->postJson('/api/property-items', $this->payload(['office_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id']);
    }

    public function test_options_include_categories_with_article_suggestions_and_office_personnel(): void
    {
        $person = Personnel::factory()->create(['office_id' => $this->office->id]);

        $response = $this->actingAs($this->userWithPermissions(self::ALL))->getJson("/api/property-items/options?office_id={$this->office->id}")->assertOk();

        $this->assertSame(['it_equipment'], array_column($response->json('data.category'), 'value'));
        $this->assertContains('Laptop Computer', $response->json('data.category.0.articles'));
        $this->assertSame([$person->id], array_column($response->json('data.personnel'), 'value'));
    }

    public function test_equipment_lists_the_items_the_rpcppe_counts_for_the_office(): void
    {
        $officer = Personnel::factory()->create(['office_id' => $this->office->id]);
        $elsewhere = Personnel::factory()->create(['office_id' => Office::factory()->create()->id]);
        $counted = Equipment::factory()->create(['current_accountable_officer_id' => $officer->id, 'disposition_status' => 'Normal', 'acquisition_cost' => 30000, 'property_no' => 'SE-001']);
        $lost = Equipment::factory()->create(['current_accountable_officer_id' => $officer->id, 'disposition_status' => 'Lost', 'property_no' => 'SE-002']);
        Equipment::factory()->create(['current_accountable_officer_id' => $officer->id, 'disposition_status' => 'Transferred']);
        Equipment::factory()->create(['current_accountable_officer_id' => $elsewhere->id, 'disposition_status' => 'Normal']);

        $response = $this->actingAs($this->userWithPermissions([Permission::PropertyItemsView]))
            ->getJson("/api/property-items/equipment?office_id={$this->office->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame(['SE-001', 1, true, 'Sample ES', $officer->fresh()->full_name], [$rows[$counted->id]['property_no'], $rows[$counted->id]['on_hand'], $rows[$counted->id]['semi_expendable'], $rows[$counted->id]['office'], $rows[$counted->id]['accountable_officer']]);
        $this->assertSame(0, $rows[$lost->id]['on_hand']);
        $this->assertArrayNotHasKey('serial_number', $rows[$counted->id]);

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))->getJson('/api/property-items/equipment')->assertForbidden();
    }

    public function test_export_downloads_the_filtered_list(): void
    {
        PropertyItem::factory()->create(['office_id' => $this->office->id, 'article' => 'Whiteboard']);
        PropertyItem::factory()->create(['office_id' => $this->office->id, 'article' => 'Mouse']);

        $response = $this->actingAs($this->userWithPermissions([Permission::PropertyItemsView]))
            ->get('/api/property-items/export?search=Whiteboard')
            ->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Whiteboard', $sheet->getCell('B5')->getValue());
        $this->assertNull($sheet->getCell('B6')->getValue());
        $this->assertDatabaseHas('activity_log', ['event' => 'property_item.exported']);
    }
}

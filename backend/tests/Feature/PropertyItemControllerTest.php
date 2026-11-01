<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\PropertyCategory;
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
            'category' => 'furniture_fixtures',
            'article' => 'Monoblock Chair',
            'description' => 'Plastic, blue',
            'unit_of_measure' => 'pc',
            'unit_value' => 350,
            'quantity' => 40,
            'on_hand_count' => 38,
            'grade_level' => 'Grade 1',
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
            ->assertJsonPath('data.category_label', 'Furniture and Fixtures')
            ->json('data.id');

        $this->assertSame($user->id, PropertyItem::findOrFail($id)->created_by_user_id);
        $this->actingAs($user)->getJson("/api/property-items/{$id}")->assertOk()->assertJsonPath('data.end_user_id', $teacher->id);
        $this->actingAs($user)->putJson("/api/property-items/{$id}", $this->payload(['quantity' => 41]))->assertOk()->assertJsonPath('data.quantity', 41);
        $this->actingAs($user)->getJson('/api/property-items?category=furniture_fixtures&search=Monoblock')->assertOk()->assertJsonPath('meta.total', 1);
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

        $this->actingAs($user)->postJson('/api/property-items', $this->payload(['end_user_id' => $outsider->id, 'grade_level' => 'Grade 99']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_user_id', 'grade_level']);
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

        $this->assertCount(count(PropertyCategory::cases()), $response->json('data.category'));
        $this->assertContains('Monoblock Chair', $response->json('data.category.2.articles'));
        $this->assertSame([$person->id], array_column($response->json('data.personnel'), 'value'));
    }

    public function test_export_downloads_the_filtered_list(): void
    {
        PropertyItem::factory()->create(['office_id' => $this->office->id, 'article' => 'Whiteboard']);
        PropertyItem::factory()->create(['office_id' => $this->office->id, 'category' => PropertyCategory::Books, 'article' => 'English']);

        $response = $this->actingAs($this->userWithPermissions([Permission::PropertyItemsView]))
            ->get('/api/property-items/export?category=furniture_fixtures')
            ->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Whiteboard', $sheet->getCell('C5')->getValue());
        $this->assertNull($sheet->getCell('C6')->getValue());
        $this->assertDatabaseHas('activity_log', ['event' => 'property_item.exported']);
    }
}

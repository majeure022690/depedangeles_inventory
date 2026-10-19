<?php

namespace Tests\Feature;

use App\Models\InternetConnectivitySurvey;
use App\Models\Office;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Office CRUD is admin-only (encoder/viewer hold no office.* permission).
 * Users, stakeholder profiles and connectivity surveys reference an
 * office through restrictOnDelete FKs, so deleting one in use is a 409.
 */
class OfficeControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'office_name' => 'Angeles Elementary School',
            'office_type' => 'Elementary School',
            'school_id' => 107022,
            'address' => '123 Main St',
            'region' => 'Region III',
            'district' => 'Angeles City',
            'division' => 'Angeles City Division',
            'contact_number' => '045-123-4567',
            'email' => 'angeles.es@deped.gov.ph',
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_reach_every_office_action(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();

        $this->actingAs($admin)->getJson('/api/offices')->assertOk();

        $this->actingAs($admin)->postJson('/api/offices', $this->validPayload(['office_name' => 'Brand New Office']))
            ->assertCreated()
            ->assertJsonPath('data.office_name', 'Brand New Office');

        $this->actingAs($admin)->getJson("/api/offices/{$office->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $office->id)
            ->assertJsonStructure(['data' => ['id', 'office_name', 'office_type', 'school_id', 'address', 'region',
                'district', 'division', 'contact_number', 'email', 'is_active']]);

        $this->actingAs($admin)->putJson("/api/offices/{$office->id}", $this->validPayload(['office_name' => $office->office_name]))
            ->assertOk();

        $this->actingAs($admin)->deleteJson("/api/offices/{$office->id}")->assertNoContent();
        $this->assertDatabaseMissing('offices', ['id' => $office->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['encoder' => ['encoder'], 'viewer' => ['viewer'], 'pending' => ['pending']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_are_forbidden_on_every_office_action(string $role): void
    {
        $user = $this->userWithRole($role);
        $office = Office::factory()->create();

        $this->actingAs($user)->getJson('/api/offices')->assertForbidden();
        $this->actingAs($user)->postJson('/api/offices', $this->validPayload())->assertForbidden();
        $this->actingAs($user)->getJson("/api/offices/{$office->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/offices/{$office->id}", $this->validPayload())->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/offices/{$office->id}")->assertForbidden();

        $this->assertSame(1, Office::count());
        $this->assertDatabaseHas('offices', ['id' => $office->id, 'office_name' => $office->office_name]);
    }

    public function test_index_is_searchable_by_name_type_and_school_id(): void
    {
        $admin = $this->admin();

        Office::factory()->create(['office_name' => 'Angeles Elementary School', 'office_type' => 'Elementary School', 'school_id' => 107022]);
        Office::factory()->create(['office_name' => 'Balibago High School', 'office_type' => 'High School', 'school_id' => 107099]);
        Office::factory()->create(['office_name' => 'Division Office', 'office_type' => 'Division Office', 'school_id' => null]);

        $names = fn (string $search) => collect($this->actingAs($admin)->getJson('/api/offices?search='.urlencode($search))->assertOk()->json('data'))
            ->pluck('office_name')->all();

        $this->assertSame(['Angeles Elementary School'], $names('Angeles'));
        $this->assertSame(['Balibago High School'], $names('High School'));
        $this->assertSame(['Balibago High School'], $names('107099'));
        $this->assertSame([], $names('Nonexistent Place'));
    }

    public function test_index_paginates_at_twenty_per_page_by_default(): void
    {
        Office::factory()->count(25)->create();

        $this->actingAs($this->admin())->getJson('/api/offices')
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'], 'links' => ['first', 'last', 'prev', 'next'], 'message']);
    }

    public function test_store_creates_a_record_and_is_audited(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/offices', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.office_type', 'Elementary School')
            ->assertJsonPath('data.school_id', 107022)
            ->assertJsonPath('data.is_active', true);

        $activity = Activity::query()->where('subject_type', Office::class)->where('subject_id', $response->json('data.id'))->sole();
        $this->assertSame('created', $activity->event);
        $this->assertSame('office.created', $activity->description);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($admin->email, $activity->properties['actor_snapshot']['email']);
    }

    public function test_store_defaults_is_active_to_true_when_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['is_active']);

        $this->actingAs($this->admin())->postJson('/api/offices', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_store_rejects_a_duplicate_office_name(): void
    {
        Office::factory()->create(['office_name' => 'Angeles Elementary School']);

        $this->actingAs($this->admin())->postJson('/api/offices', $this->validPayload())
            ->assertJsonValidationErrors(['office_name']);

        $this->assertSame(1, Office::where('office_name', 'Angeles Elementary School')->count());
    }

    public function test_store_validates_email_school_id_and_office_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/offices', $this->validPayload(['email' => 'not-an-email']))
            ->assertJsonValidationErrors(['email']);
        $this->actingAs($admin)->postJson('/api/offices', $this->validPayload(['school_id' => 'not-a-number']))
            ->assertJsonValidationErrors(['school_id']);
        $this->actingAs($admin)->postJson('/api/offices', $this->validPayload(['office_name' => null]))
            ->assertJsonValidationErrors(['office_name']);

        $this->assertSame(0, Office::count());
    }

    public function test_update_updates_the_record_and_audits_the_diff(): void
    {
        $office = Office::factory()->create(['office_name' => 'Old Name', 'is_active' => true]);

        $this->actingAs($this->admin())->putJson("/api/offices/{$office->id}", $this->validPayload(['office_name' => 'New Name']))
            ->assertOk()
            ->assertJsonPath('data.office_name', 'New Name');

        $activity = Activity::query()->where('subject_id', $office->id)->where('event', 'updated')->sole();
        $this->assertSame('Old Name', $activity->attribute_changes['old']['office_name']);
        $this->assertSame('New Name', $activity->attribute_changes['attributes']['office_name']);
        $this->assertArrayNotHasKey('updated_at', $activity->attribute_changes['attributes']);
    }

    public function test_update_allows_saving_a_record_with_its_own_unchanged_name(): void
    {
        $office = Office::factory()->create(['office_name' => 'Angeles Elementary School']);

        $this->actingAs($this->admin())->putJson("/api/offices/{$office->id}", $this->validPayload([
            'district' => 'Updated District',
        ]))->assertOk();

        $this->assertSame('Updated District', $office->fresh()->district);
    }

    public function test_update_rejects_a_name_duplicating_a_different_office(): void
    {
        Office::factory()->create(['office_name' => 'Taken Name']);
        $office = Office::factory()->create(['office_name' => 'Original Name']);

        $this->actingAs($this->admin())->putJson("/api/offices/{$office->id}", $this->validPayload(['office_name' => 'Taken Name']))
            ->assertJsonValidationErrors(['office_name']);

        $this->assertSame('Original Name', $office->fresh()->office_name);
    }

    public function test_destroy_deletes_an_unreferenced_office_and_is_audited(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/offices/{$office->id}")->assertNoContent();

        $this->assertDatabaseMissing('offices', ['id' => $office->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Office::class,
            'subject_id' => $office->id,
            'event' => 'deleted',
            'causer_id' => $admin->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dependents(): array
    {
        return ['user' => ['user'], 'stakeholder profile' => ['profile'], 'connectivity survey' => ['survey']];
    }

    #[DataProvider('dependents')]
    public function test_destroy_is_a_409_when_something_still_references_the_office(string $dependent): void
    {
        $office = Office::factory()->create();

        match ($dependent) {
            'user' => User::factory()->create(['office_id' => $office->id]),
            'profile' => StakeholderProfile::create(['office_id' => $office->id]),
            'survey' => InternetConnectivitySurvey::create(['office_id' => $office->id]),
        };

        $this->actingAs($this->admin())->deleteJson("/api/offices/{$office->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'This office is still in use and cannot be deleted — deactivate it instead.');

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
        $this->assertDatabaseMissing('activity_log', ['subject_type' => Office::class, 'subject_id' => $office->id, 'event' => 'deleted']);
    }
}

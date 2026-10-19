<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InternetConnectivitySurvey;
use App\Models\Office;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Office CRUD is architecturally identical to Equipment/Personnel (plain
 * resource, no per-user scoping) except that all four permissions
 * (office.view/.create/.edit/.delete) are admin-only per
 * RolePermissionSeeder — encoder/viewer hold none of them, unlike their
 * broad access to Equipment/Personnel/ISP accounts. The destroy() guard
 * (restrictOnDelete FKs on users/stakeholder_profiles/
 * internet_connectivity_surveys, caught and rethrown as a 'delete'
 * validation error — see OfficeController::destroy()'s doc-comment) is
 * this feature's highest-risk path and gets dedicated coverage per FK.
 */
class OfficeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function encoder(): User
    {
        $user = User::factory()->create();
        $user->assignRole('encoder');

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');

        return $user;
    }

    private function pending(): User
    {
        $user = User::factory()->create();
        $user->assignRole('pending');

        return $user;
    }

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

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_admin_can_reach_every_office_action(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();

        $this->actingAs($admin)->get(route('offices.index'))->assertOk();
        $this->actingAs($admin)->get(route('offices.create'))->assertOk();

        $response = $this->actingAs($admin)->post(route('offices.store'), $this->validPayload([
            'office_name' => 'Brand New Office',
        ]));
        $created = Office::where('office_name', 'Brand New Office')->firstOrFail();
        $response->assertRedirect(route('offices.edit', $created));

        $this->actingAs($admin)->get(route('offices.edit', $office))->assertOk();

        $this->actingAs($admin)->put(route('offices.update', $office), $this->validPayload([
            'office_name' => $office->office_name,
        ]))->assertRedirect(route('offices.edit', $office));

        $this->actingAs($admin)->delete(route('offices.destroy', $office))
            ->assertRedirect(route('offices.index'));
        $this->assertDatabaseMissing('offices', ['id' => $office->id]);
    }

    public function test_encoder_is_forbidden_on_every_office_action(): void
    {
        $encoder = $this->encoder();
        $office = Office::factory()->create();

        $this->actingAs($encoder)->get(route('offices.index'))->assertForbidden();
        $this->actingAs($encoder)->get(route('offices.create'))->assertForbidden();
        $this->actingAs($encoder)->post(route('offices.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($encoder)->get(route('offices.edit', $office))->assertForbidden();
        $this->actingAs($encoder)->put(route('offices.update', $office), $this->validPayload())->assertForbidden();
        $this->actingAs($encoder)->delete(route('offices.destroy', $office))->assertForbidden();

        $this->assertDatabaseHas('offices', ['id' => $office->id, 'office_name' => $office->office_name]);
        $this->assertSame(1, Office::count());
    }

    public function test_viewer_is_forbidden_on_every_office_action(): void
    {
        $viewer = $this->viewer();
        $office = Office::factory()->create();

        $this->actingAs($viewer)->get(route('offices.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('offices.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('offices.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->get(route('offices.edit', $office))->assertForbidden();
        $this->actingAs($viewer)->put(route('offices.update', $office), $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('offices.destroy', $office))->assertForbidden();
    }

    public function test_pending_role_is_forbidden_on_every_office_action(): void
    {
        $pending = $this->pending();
        $office = Office::factory()->create();

        $this->actingAs($pending)->get(route('offices.index'))->assertForbidden();
        $this->actingAs($pending)->get(route('offices.create'))->assertForbidden();
        $this->actingAs($pending)->post(route('offices.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($pending)->get(route('offices.edit', $office))->assertForbidden();
        $this->actingAs($pending)->put(route('offices.update', $office), $this->validPayload())->assertForbidden();
        $this->actingAs($pending)->delete(route('offices.destroy', $office))->assertForbidden();

        // No write side effects from any forbidden request.
        $this->assertSame(1, Office::count());
    }

    // ------------------------------------------------------------------
    // Index
    // ------------------------------------------------------------------

    public function test_index_is_paginated_and_searchable_by_name_type_and_school_id(): void
    {
        $admin = $this->admin();

        Office::factory()->create(['office_name' => 'Angeles Elementary School', 'office_type' => 'Elementary School', 'school_id' => 107022]);
        Office::factory()->create(['office_name' => 'Balibago High School', 'office_type' => 'High School', 'school_id' => 107099]);
        Office::factory()->create(['office_name' => 'Division Office', 'office_type' => 'Division Office', 'school_id' => null]);

        // Search by name.
        $response = $this->actingAs($admin)->get(route('offices.index', ['search' => 'Angeles']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('offices.data', fn ($data) => collect($data)->pluck('office_name')->all() === ['Angeles Elementary School'])
        );

        // Search by office_type.
        $this->actingAs($admin)->get(route('offices.index', ['search' => 'High School']))
            ->assertInertia(fn ($page) => $page
                ->where('offices.data', fn ($data) => collect($data)->pluck('office_name')->all() === ['Balibago High School'])
            );

        // Search by school_id.
        $this->actingAs($admin)->get(route('offices.index', ['search' => '107099']))
            ->assertInertia(fn ($page) => $page
                ->where('offices.data', fn ($data) => collect($data)->pluck('office_name')->all() === ['Balibago High School'])
            );

        // No matches for a nonsense term.
        $this->actingAs($admin)->get(route('offices.index', ['search' => 'Nonexistent Place']))
            ->assertInertia(fn ($page) => $page->where('offices.data', []));
    }

    public function test_index_paginates_at_fifteen_per_page(): void
    {
        $admin = $this->admin();
        Office::factory()->count(20)->create();

        $response = $this->actingAs($admin)->get(route('offices.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('offices.data', fn ($data) => count($data) === 15)
            ->where('offices.total', 20)
        );
    }

    // ------------------------------------------------------------------
    // Store
    // ------------------------------------------------------------------

    public function test_store_creates_a_record_redirects_and_writes_an_audit_log_entry(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('offices.store'), $this->validPayload());

        $office = Office::where('office_name', 'Angeles Elementary School')->firstOrFail();
        $response->assertRedirect(route('offices.edit', $office));

        $this->assertSame('Elementary School', $office->office_type);
        $this->assertSame(107022, $office->school_id);
        $this->assertTrue($office->is_active);

        $log = AuditLog::where('action', 'office.created')->where('subject_id', $office->id)->sole();
        $this->assertSame(Office::class, $log->subject_type);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_store_defaults_is_active_to_true_when_omitted(): void
    {
        $admin = $this->admin();
        $payload = $this->validPayload();
        unset($payload['is_active']);

        $this->actingAs($admin)->post(route('offices.store'), $payload)->assertSessionHasNoErrors();

        $office = Office::where('office_name', 'Angeles Elementary School')->firstOrFail();
        $this->assertTrue($office->is_active);
    }

    public function test_store_rejects_a_duplicate_office_name(): void
    {
        $admin = $this->admin();
        Office::factory()->create(['office_name' => 'Angeles Elementary School']);

        $this->actingAs($admin)->post(route('offices.store'), $this->validPayload())
            ->assertSessionHasErrors(['office_name']);

        $this->assertSame(1, Office::where('office_name', 'Angeles Elementary School')->count());
    }

    public function test_store_rejects_a_malformed_email(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('offices.store'), $this->validPayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors(['email']);

        $this->assertDatabaseMissing('offices', ['office_name' => 'Angeles Elementary School']);
    }

    public function test_store_rejects_a_non_integer_school_id(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('offices.store'), $this->validPayload(['school_id' => 'not-a-number']))
            ->assertSessionHasErrors(['school_id']);

        $this->assertDatabaseMissing('offices', ['office_name' => 'Angeles Elementary School']);
    }

    public function test_store_requires_office_name(): void
    {
        $admin = $this->admin();
        $payload = $this->validPayload();
        unset($payload['office_name']);

        $this->actingAs($admin)->post(route('offices.store'), $payload)
            ->assertSessionHasErrors(['office_name']);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_updates_the_record_and_writes_a_diffed_audit_log_entry(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create(['office_name' => 'Old Name', 'is_active' => true]);

        $this->actingAs($admin)->put(route('offices.update', $office), $this->validPayload([
            'office_name' => 'New Name',
        ]))->assertRedirect(route('offices.edit', $office));

        $office->refresh();
        $this->assertSame('New Name', $office->office_name);

        $log = AuditLog::where('action', 'office.updated')->where('subject_id', $office->id)->sole();
        $this->assertSame('Old Name', $log->properties['changes']['office_name']['old']);
        $this->assertSame('New Name', $log->properties['changes']['office_name']['new']);
        $this->assertArrayNotHasKey('updated_at', $log->properties['changes']);
    }

    public function test_update_allows_saving_a_record_with_its_own_unchanged_name(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create(['office_name' => 'Angeles Elementary School']);

        $this->actingAs($admin)->put(route('offices.update', $office), $this->validPayload([
            'office_name' => 'Angeles Elementary School',
            'district' => 'Updated District',
        ]))->assertSessionHasNoErrors();

        $office->refresh();
        $this->assertSame('Updated District', $office->district);
    }

    public function test_update_rejects_a_name_duplicating_a_different_office(): void
    {
        $admin = $this->admin();
        Office::factory()->create(['office_name' => 'Taken Name']);
        $office = Office::factory()->create(['office_name' => 'Original Name']);

        $this->actingAs($admin)->put(route('offices.update', $office), $this->validPayload([
            'office_name' => 'Taken Name',
        ]))->assertSessionHasErrors(['office_name']);

        $office->refresh();
        $this->assertSame('Original Name', $office->office_name);
    }

    // ------------------------------------------------------------------
    // Destroy
    // ------------------------------------------------------------------

    public function test_destroy_deletes_an_unreferenced_office_and_writes_an_audit_log_entry(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();

        $response = $this->actingAs($admin)->delete(route('offices.destroy', $office));

        $response->assertRedirect(route('offices.index'));
        $this->assertDatabaseMissing('offices', ['id' => $office->id]);

        $log = AuditLog::where('action', 'office.deleted')->where('subject_id', $office->id)->sole();
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_destroy_is_blocked_when_a_user_references_the_office(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();
        User::factory()->create(['office_id' => $office->id]);

        $this->actingAs($admin)->delete(route('offices.destroy', $office))
            ->assertSessionHasErrors(['delete']);

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
    }

    public function test_destroy_is_blocked_when_a_stakeholder_profile_references_the_office(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();
        StakeholderProfile::create(['office_id' => $office->id]);

        $this->actingAs($admin)->delete(route('offices.destroy', $office))
            ->assertSessionHasErrors(['delete']);

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
        $this->assertDatabaseHas('stakeholder_profiles', ['office_id' => $office->id]);
    }

    public function test_destroy_is_blocked_when_an_internet_connectivity_survey_references_the_office(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();
        InternetConnectivitySurvey::create(['office_id' => $office->id]);

        $this->actingAs($admin)->delete(route('offices.destroy', $office))
            ->assertSessionHasErrors(['delete']);

        $this->assertDatabaseHas('offices', ['id' => $office->id]);
        $this->assertDatabaseHas('internet_connectivity_surveys', ['office_id' => $office->id]);
    }

    public function test_destroy_does_not_write_an_audit_log_when_blocked_by_the_fk_guard(): void
    {
        $admin = $this->admin();
        $office = Office::factory()->create();
        User::factory()->create(['office_id' => $office->id]);

        $this->actingAs($admin)->delete(route('offices.destroy', $office));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'office.deleted', 'subject_id' => $office->id]);
    }
}

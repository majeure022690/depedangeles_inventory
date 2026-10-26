<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\PersonnelLibraryType;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Tier 1 fields (position, RO office, SDO office) are real FKs validated
 * against active reference rows; Tier 2 (suffix, separation_cause) are
 * validated strings; `fund_source` is an array of personnel_libraries ids.
 */
class PersonnelControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([Permission::PersonnelView, Permission::PersonnelCreate, Permission::PersonnelEdit]);
    }

    public function test_full_name_generated_column_matches_source_workbook_format(): void
    {
        $person = Personnel::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'DelaCruz',
            'middle_name' => 'Reyes',
            'employee_id' => '12345',
        ]);

        $this->assertSame('DelaCruz, Juan Reyes - 12345', $person->fresh()->full_name);
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/personnel')->assertUnauthorized();
    }

    public function test_store_validates_required_fields_and_creates_record(): void
    {
        $user = $this->editor();

        $this->actingAs($user)->postJson('/api/personnel', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id', 'last_name', 'first_name']);

        $positionId = Position::where('name', 'Administrative Officer II')->value('id');
        $suffix = PersonnelLibrary::activeValues(PersonnelLibraryType::NameSuffix)[0];

        $response = $this->actingAs($user)->postJson('/api/personnel', [
            'employee_id' => 'EMP-100',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => $positionId,
            'suffix' => $suffix,
        ])->assertCreated()
            ->assertJsonPath('data.employee_id', 'EMP-100')
            ->assertJsonPath('data.position_id', $positionId)
            ->assertJsonPath('data.position', 'Administrative Officer II')
            ->assertJsonPath('data.full_name', 'Santos, Maria - EMP-100');

        $personnel = Personnel::findOrFail($response->json('data.id'));
        $this->assertSame($suffix, $personnel->suffix);

        $activity = Activity::query()->where('subject_type', Personnel::class)->where('subject_id', $personnel->id)->sole();
        $this->assertSame('created', $activity->event);
        $this->assertSame('personnel.created', $activity->description);
        $this->assertTrue($activity->causer->is($user));
        $this->assertSame('EMP-100', $activity->attribute_changes['attributes']['employee_id']);
    }

    public function test_store_rejects_a_nonexistent_position_id(): void
    {
        $this->actingAs($this->editor())->postJson('/api/personnel', [
            'employee_id' => 'EMP-101',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => 999999,
        ])->assertJsonValidationErrors(['position_id']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-101']);
    }

    public function test_store_rejects_a_position_id_that_has_been_deactivated(): void
    {
        $position = Position::where('name', 'Administrative Officer II')->firstOrFail();
        $position->update(['is_active' => false]);

        $this->actingAs($this->editor())->postJson('/api/personnel', [
            'employee_id' => 'EMP-102',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => $position->id,
        ])->assertJsonValidationErrors(['position_id']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-102']);
    }

    public function test_store_rejects_a_lookup_backed_value_not_in_the_active_list(): void
    {
        $this->actingAs($this->editor())->postJson('/api/personnel', [
            'employee_id' => 'EMP-103',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'suffix' => 'Not A Real Suffix',
        ])->assertJsonValidationErrors(['suffix']);
    }

    public function test_store_accepts_ro_office_sdo_office_and_fund_source_ids(): void
    {
        $roOfficeId = RoOffice::query()->active()->value('id');
        $sdoOfficeId = SdoOffice::query()->active()->value('id');
        $fundSourceIds = PersonnelLibrary::query()
            ->where('type', PersonnelLibraryType::TeachersFundingSource)
            ->active()
            ->limit(2)
            ->pluck('id')
            ->all();

        $this->actingAs($this->editor())->postJson('/api/personnel', [
            'employee_id' => 'EMP-104',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'ro_office_id' => $roOfficeId,
            'sdo_office_id' => $sdoOfficeId,
            'non_deped_funded' => true,
            'fund_source' => $fundSourceIds,
        ])->assertCreated()
            ->assertJsonPath('data.fund_source', $fundSourceIds);

        $personnel = Personnel::where('employee_id', 'EMP-104')->firstOrFail();
        $this->assertSame($roOfficeId, $personnel->ro_office_id);
        $this->assertSame($sdoOfficeId, $personnel->sdo_office_id);
        $this->assertSame($fundSourceIds, $personnel->fund_source);
    }

    public function test_store_rejects_a_fund_source_id_not_in_the_active_teachers_funding_source_list(): void
    {
        // A real, active row of the wrong type.
        $wrongTypeId = PersonnelLibrary::query()
            ->where('type', PersonnelLibraryType::CauseOfSeparation)
            ->active()
            ->value('id');

        $this->actingAs($this->editor())->postJson('/api/personnel', [
            'employee_id' => 'EMP-105',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'fund_source' => [$wrongTypeId],
        ])->assertJsonValidationErrors(['fund_source.0']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-105']);
    }

    public function test_update_and_soft_delete_are_audited(): void
    {
        $admin = $this->userWithPermissions([Permission::PersonnelView, Permission::PersonnelEdit, Permission::PersonnelDelete]);
        $person = Personnel::factory()->create(['employee_id' => 'EMP-200', 'last_name' => 'Original']);

        $this->actingAs($admin)->putJson("/api/personnel/{$person->id}", [
            'employee_id' => 'EMP-200',
            'last_name' => 'Updated',
            'first_name' => $person->first_name,
        ])->assertOk()->assertJsonPath('data.last_name', 'Updated');

        $this->assertDatabaseHas('personnel', ['id' => $person->id, 'last_name' => 'Updated']);

        $updated = Activity::query()->where('subject_id', $person->id)->where('event', 'updated')->sole();
        $this->assertSame('personnel.updated', $updated->description);
        $this->assertSame('Original', $updated->attribute_changes['old']['last_name']);
        $this->assertSame('Updated', $updated->attribute_changes['attributes']['last_name']);

        $this->actingAs($admin)->deleteJson("/api/personnel/{$person->id}")->assertNoContent();

        $this->assertSoftDeleted('personnel', ['id' => $person->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Personnel::class,
            'subject_id' => $person->id,
            'event' => 'deleted',
            'description' => 'personnel.deleted',
        ]);
    }

    public function test_own_scope_view_only_user_can_read_its_record_but_not_write(): void
    {
        $person = Personnel::factory()->create();
        $reader = $this->userWithPermissions([Permission::PersonnelView], ['employee_no' => $person->employee_id], DataScope::Own);

        $this->actingAs($reader)->getJson('/api/personnel')->assertOk()->assertJsonPath('data.0.id', $person->id);
        $this->actingAs($reader)->getJson("/api/personnel/{$person->id}")->assertOk();
        $this->actingAs($reader)->postJson('/api/personnel', ['employee_id' => 'X'])->assertForbidden();
        $this->actingAs($reader)->putJson("/api/personnel/{$person->id}", ['employee_id' => 'X'])->assertForbidden();
        $this->actingAs($reader)->deleteJson("/api/personnel/{$person->id}")->assertForbidden();

        $this->assertNotSoftDeleted('personnel', ['id' => $person->id]);
    }

    public function test_editor_without_personnel_delete_cannot_delete_and_the_denial_is_audited(): void
    {
        $editor = $this->editor();
        $person = Personnel::factory()->create();

        // No personnel.delete: the route middleware fails fast.
        $this->actingAs($editor)->deleteJson("/api/personnel/{$person->id}")->assertForbidden();
        $this->assertNotSoftDeleted('personnel', ['id' => $person->id]);

        // The Policy (authoritative gate) audits a denial when reached.
        $this->assertFalse($editor->can('delete', $person));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'authorization.denied',
            'subject_type' => Personnel::class,
            'subject_id' => $person->id,
            'causer_id' => $editor->id,
        ]);
    }

    public function test_show_returns_the_detail_shape_with_related_names(): void
    {
        $position = Position::where('name', 'Administrative Officer II')->firstOrFail();
        $roOffice = RoOffice::query()->active()->firstOrFail();
        $sdoOffice = SdoOffice::query()->active()->firstOrFail();

        $person = Personnel::factory()->create([
            'position_id' => $position->id,
            'ro_office_id' => $roOffice->id,
            'sdo_office_id' => $sdoOffice->id,
            'date_hired' => '2020-06-01',
        ]);

        $admin = $this->userWithPermissions([Permission::PersonnelView]);

        $this->actingAs($admin)->getJson('/api/personnel')
            ->assertOk()
            ->assertJsonPath('data.0.position', $position->name)
            ->assertJsonPath('data.0.ro_division', $roOffice->name)
            ->assertJsonPath('data.0.division_unit', $sdoOffice->name);

        $this->actingAs($admin)->getJson("/api/personnel/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.position', $position->name)
            ->assertJsonPath('data.position_id', $position->id)
            ->assertJsonPath('data.ro_division', $roOffice->name)
            ->assertJsonPath('data.ro_office_id', $roOffice->id)
            ->assertJsonPath('data.division_unit', $sdoOffice->name)
            ->assertJsonPath('data.sdo_office_id', $sdoOffice->id)
            ->assertJsonPath('data.date_hired', '2020-06-01')
            ->assertJsonMissingPath('data.deleted_at');
    }

    public function test_options_ship_id_valued_tier1_and_string_valued_tier2_options(): void
    {
        $response = $this->actingAs($this->editor())->getJson('/api/personnel/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'position', 'ro_office', 'sdo_office', 'name_suffix', 'cause_of_separation', 'teachers_funding_source',
            ]]);

        $this->assertIsInt($response->json('data.position.0.value'));
        $this->assertIsString($response->json('data.position.0.label'));
        $this->assertIsInt($response->json('data.teachers_funding_source.0.value'));
        $this->assertIsString($response->json('data.name_suffix.0.value'));
    }

    public function test_options_are_reachable_with_create_only_and_forbidden_without_any_personnel_permission(): void
    {
        $this->actingAs($this->userWithPermissions([Permission::PersonnelCreate]))
            ->getJson('/api/personnel/options')->assertOk();

        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))
            ->getJson('/api/personnel/options')->assertForbidden()->assertJsonMissingPath('code');
    }

    public function test_index_filters_by_search_and_status_and_paginates(): void
    {
        Personnel::factory()->create(['first_name' => 'Juan', 'last_name' => 'DelaCruz']);
        Personnel::factory()->inactive()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        Personnel::factory()->count(3)->create();

        $user = $this->editor();

        $this->actingAs($user)->getJson('/api/personnel?search=DelaCruz')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'DelaCruz');

        // Default status is active only.
        $this->actingAs($user)->getJson('/api/personnel')->assertJsonPath('meta.total', 4);
        $this->actingAs($user)->getJson('/api/personnel?status=inactive')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last_name', 'Reyes');
        $this->actingAs($user)->getJson('/api/personnel?status=all')->assertJsonPath('meta.total', 5);

        $this->actingAs($user)->getJson('/api/personnel?per_page=2&page=2')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data');

        // per_page is clamped to 100.
        $this->actingAs($user)->getJson('/api/personnel?per_page=500')->assertJsonPath('meta.per_page', 100);
    }

    public function test_index_sorts_only_by_whitelisted_columns(): void
    {
        Personnel::factory()->create(['employee_id' => 'B-2', 'last_name' => 'Alpha']);
        Personnel::factory()->create(['employee_id' => 'A-1', 'last_name' => 'Zulu']);

        $user = $this->editor();

        $this->actingAs($user)->getJson('/api/personnel?sort=employee_id&direction=asc')
            ->assertJsonPath('data.0.employee_id', 'A-1');
        $this->actingAs($user)->getJson('/api/personnel?sort=employee_id&direction=desc')
            ->assertJsonPath('data.0.employee_id', 'B-2');

        // Unknown sort keys fall back to the default (last name) order.
        $this->actingAs($user)->getJson('/api/personnel?sort=password&direction=desc')
            ->assertOk()->assertJsonPath('data.0.last_name', 'Alpha');
    }
}

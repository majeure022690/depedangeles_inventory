<?php

namespace Tests\Feature;

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
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Personnel's Tier 1 fields (position, RO office, SDO office) are real
 * foreign keys as of the lookup-normalization ADR's Step 2 — fixtures here
 * pull real ids from the reference tables (seeded by ReferenceDataSeeder)
 * rather than the old Lookup/LookupType system, which Personnel no longer
 * consults at all. Tier 2 fields (suffix, separation_cause) stay
 * string-validated, now against the real seeded PersonnelLibrary rows.
 * `fund_source` is the one JSON-array field — its elements are now
 * `personnel_libraries` row ids (teachers_funding_source type), not value
 * strings.
 */
class PersonnelControllerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_store_validates_required_fields_and_creates_record(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->post(route('personnel.store'), [])
            ->assertSessionHasErrors(['employee_id', 'last_name', 'first_name']);

        $positionId = Position::where('name', 'Administrative Officer II')->value('id');
        $suffix = PersonnelLibrary::activeValues(PersonnelLibraryType::NameSuffix)[0];

        $response = $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-100',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => $positionId,
            'suffix' => $suffix,
        ]);

        $response->assertRedirect(route('personnel.index'));

        $personnel = Personnel::where('employee_id', 'EMP-100')->firstOrFail();
        $this->assertSame($positionId, $personnel->position_id);
        $this->assertSame('Administrative Officer II', $personnel->personnelPosition->name);
        $this->assertSame($suffix, $personnel->suffix);
    }

    public function test_store_rejects_a_nonexistent_position_id(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-101',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => 999999,
        ])->assertSessionHasErrors(['position_id']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-101']);
    }

    public function test_store_rejects_a_position_id_that_has_been_deactivated(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $position = Position::where('name', 'Administrative Officer II')->firstOrFail();
        $position->update(['is_active' => false]);

        $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-102',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'position_id' => $position->id,
        ])->assertSessionHasErrors(['position_id']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-102']);
    }

    public function test_store_rejects_a_lookup_backed_value_not_in_the_active_list(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-103',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'suffix' => 'Not A Real Suffix',
        ])->assertSessionHasErrors(['suffix']);
    }

    public function test_store_accepts_ro_office_sdo_office_and_fund_source_ids(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $roOfficeId = RoOffice::query()->active()->value('id');
        $sdoOfficeId = SdoOffice::query()->active()->value('id');
        $fundSourceIds = PersonnelLibrary::query()
            ->where('type', PersonnelLibraryType::TeachersFundingSource)
            ->active()
            ->limit(2)
            ->pluck('id')
            ->all();

        $response = $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-104',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'ro_office_id' => $roOfficeId,
            'sdo_office_id' => $sdoOfficeId,
            'non_deped_funded' => true,
            'fund_source' => $fundSourceIds,
        ]);

        $response->assertRedirect(route('personnel.index'));

        $personnel = Personnel::where('employee_id', 'EMP-104')->firstOrFail();
        $this->assertSame($roOfficeId, $personnel->ro_office_id);
        $this->assertSame($sdoOfficeId, $personnel->sdo_office_id);
        $this->assertSame($fundSourceIds, $personnel->fund_source);
    }

    public function test_store_rejects_a_fund_source_id_not_in_the_active_teachers_funding_source_list(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        // A real, active PersonnelLibrary row — but of the wrong type
        // (cause_of_separation, not teachers_funding_source), so it must
        // still be rejected for fund_source.
        $wrongTypeId = PersonnelLibrary::query()
            ->where('type', PersonnelLibraryType::CauseOfSeparation)
            ->active()
            ->value('id');

        $this->actingAs($user)->post(route('personnel.store'), [
            'employee_id' => 'EMP-105',
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'fund_source' => [$wrongTypeId],
        ])->assertSessionHasErrors(['fund_source.0']);

        $this->assertDatabaseMissing('personnel', ['employee_id' => 'EMP-105']);
    }

    public function test_update_and_soft_delete(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        // Exercises both update and delete, so the acting user needs the
        // admin role — Encoder can edit but cannot delete.
        $user = User::factory()->create();
        $user->assignRole('admin');
        $person = Personnel::factory()->create(['employee_id' => 'EMP-200']);

        $this->actingAs($user)->put(route('personnel.update', $person), [
            'employee_id' => 'EMP-200',
            'last_name' => 'Updated',
            'first_name' => $person->first_name,
        ])->assertRedirect(route('personnel.index'));

        $this->assertDatabaseHas('personnel', ['id' => $person->id, 'last_name' => 'Updated']);

        $this->actingAs($user)->delete(route('personnel.destroy', $person))
            ->assertRedirect(route('personnel.index'));

        $this->assertSoftDeleted('personnel', ['id' => $person->id]);
    }

    public function test_index_and_edit_props_show_related_names_not_raw_ids(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $position = Position::where('name', 'Administrative Officer II')->firstOrFail();
        $roOffice = RoOffice::query()->active()->firstOrFail();
        $sdoOffice = SdoOffice::query()->active()->firstOrFail();

        $person = Personnel::factory()->create([
            'position_id' => $position->id,
            'ro_office_id' => $roOffice->id,
            'sdo_office_id' => $sdoOffice->id,
        ]);

        $this->actingAs($user)->get(route('personnel.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personnel.data.0.position', $position->name)
                ->where('personnel.data.0.ro_division', $roOffice->name)
                ->where('personnel.data.0.division_unit', $sdoOffice->name)
            );

        $this->actingAs($user)->get(route('personnel.edit', $person))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personnel.position', $position->name)
                ->where('personnel.position_id', $position->id)
                ->where('personnel.ro_division', $roOffice->name)
                ->where('personnel.ro_office_id', $roOffice->id)
                ->where('personnel.division_unit', $sdoOffice->name)
                ->where('personnel.sdo_office_id', $sdoOffice->id)
            );
    }

    public function test_create_and_edit_ship_id_valued_tier1_and_tier2_options(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('personnel.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('options.position')
                ->has('options.ro_office')
                ->has('options.sdo_office')
                ->has('options.name_suffix')
                ->has('options.cause_of_separation')
                ->has('options.teachers_funding_source')
                ->where('options.position.0.value', fn ($value) => is_int($value))
                ->where('options.teachers_funding_source.0.value', fn ($value) => is_int($value))
                ->where('options.name_suffix.0.value', fn ($value) => is_string($value))
            );
    }

    public function test_search_and_status_scopes_filter_the_index_query(): void
    {
        Personnel::factory()->create(['first_name' => 'Juan', 'last_name' => 'DelaCruz']);
        Personnel::factory()->inactive()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->assertSame(1, Personnel::search('DelaCruz')->count());
        $this->assertSame(1, Personnel::active()->count());
        $this->assertSame(1, Personnel::query()->where('inactive', true)->count());
    }
}

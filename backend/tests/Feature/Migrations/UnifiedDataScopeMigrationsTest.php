<?php

namespace Tests\Feature\Migrations;

use App\Models\Equipment;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnifiedDataScopeMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const USER_MORPH = 'App\\Models\\User';

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    public function test_roles_default_to_own_and_down_restores_all(): void
    {
        DB::table('roles')->insert(['name' => 'after-up', 'guard_name' => 'web', 'label' => 'After']);
        $this->assertSame('own', DB::table('roles')->where('name', 'after-up')->value('data_scope'));

        $migration = $this->migration('2026_10_26_000001_default_roles_data_scope_to_own');
        $migration->down();
        DB::table('roles')->insert(['name' => 'after-down', 'guard_name' => 'web', 'label' => 'After']);
        $this->assertSame('all', DB::table('roles')->where('name', 'after-down')->value('data_scope'));
        $this->assertSame('own', DB::table('roles')->where('name', 'after-up')->value('data_scope'));

        $migration->up();
        DB::table('roles')->insert(['name' => 'after-reup', 'guard_name' => 'web', 'label' => 'After']);
        $this->assertSame('own', DB::table('roles')->where('name', 'after-reup')->value('data_scope'));
    }

    public function test_personnel_user_link_is_backfilled_only_from_unambiguous_email_matches(): void
    {
        $migration = $this->migration('2026_10_26_000002_add_user_id_to_personnel');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('personnel', 'user_id'));

        $unique = User::factory()->create(['email' => 'unique@deped.gov.ph']);
        $sharedByPersonnel = User::factory()->create(['email' => 'shared@deped.gov.ph']);
        User::factory()->create(['email' => 'twin@deped.gov.ph']);
        User::factory()->create(['email' => 'TWIN@deped.gov.ph']);
        $deletedMatch = User::factory()->create(['email' => 'deleted@deped.gov.ph']);

        $person = fn (string $email, bool $trashed = false) => DB::table('personnel')->insertGetId([
            'employee_id' => uniqid('E'), 'last_name' => 'L', 'first_name' => 'F',
            'deped_email' => $email, 'deleted_at' => $trashed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $matched = $person('  Unique@DepEd.gov.ph ');
        $sharedA = $person('shared@deped.gov.ph');
        $sharedB = $person('SHARED@deped.gov.ph');
        $twin = $person('twin@deped.gov.ph');
        $trashed = $person('deleted@deped.gov.ph', true);
        $noUser = $person('nobody@deped.gov.ph');
        $blank = $person('');

        $migration->up();

        $links = DB::table('personnel')->pluck('user_id', 'id');
        $this->assertSame($unique->id, (int) $links[$matched]);
        $this->assertNull($links[$sharedA]);
        $this->assertNull($links[$sharedB]);
        $this->assertNull($links[$twin]);
        $this->assertNull($links[$trashed]);
        $this->assertNull($links[$noUser]);
        $this->assertNull($links[$blank]);
        $this->assertNotNull($sharedByPersonnel->id);
        $this->assertNotNull($deletedMatch->id);

        $this->assertSame('L, F - '.DB::table('personnel')->where('id', $matched)->value('employee_id'), Personnel::findOrFail($matched)->full_name);

        $audit = $this->migrationAudits();
        $this->assertCount(1, $audit);
        $this->assertSame('2026_10_26_000002_add_user_id_to_personnel', $audit[0]['description']);
        $this->assertNull($audit[0]['causer_id']);
        $this->assertSame([['personnel_id' => $matched, 'user_id' => $unique->id, 'email' => 'unique@deped.gov.ph']], $audit[0]['properties']['linked']);
        $this->assertEqualsCanonicalizing(['shared@deped.gov.ph', 'twin@deped.gov.ph'], array_column($audit[0]['properties']['skipped_ambiguous'], 'email'));
    }

    public function test_personnel_backfill_writes_no_audit_row_when_nothing_links(): void
    {
        $migration = $this->migration('2026_10_26_000002_add_user_id_to_personnel');
        $migration->down();
        User::factory()->create(['email' => 'lonely@deped.gov.ph']);

        $migration->up();

        $this->assertSame([], $this->migrationAudits());
    }

    /**
     * @return list<array{description: string, causer_id: int|null, properties: array<string, mixed>}>
     */
    private function migrationAudits(): array
    {
        return DB::table('activity_log')
            ->where('event', 'system.data_scope_migration')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'description' => $row->description,
                'causer_id' => $row->causer_id,
                'properties' => json_decode($row->properties, true),
            ])
            ->all();
    }

    public function test_personnel_user_link_is_unique(): void
    {
        $user = User::factory()->create();
        Personnel::factory()->create(['user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Personnel::factory()->create(['user_id' => $user->id]);
    }

    public function test_equipment_creator_is_backfilled_from_the_first_created_activity(): void
    {
        $migration = $this->migration('2026_10_26_000003_add_created_by_user_id_to_equipment');

        $creator = User::factory()->create();
        $later = User::factory()->create();
        $logged = Equipment::factory()->create();
        $systemMade = Equipment::factory()->create();
        $ghostMade = Equipment::factory()->create();
        $untouched = Equipment::factory()->create();
        DB::table('activity_log')->delete();

        $log = fn (int $subjectId, ?int $causerId, string $event = 'created', ?string $causerType = self::USER_MORPH) => DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => "equipment.{$event}", 'event' => $event,
            'subject_type' => Equipment::class, 'subject_id' => $subjectId,
            'causer_type' => $causerId === null ? null : $causerType, 'causer_id' => $causerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $log($logged->id, $creator->id);
        $log($logged->id, $later->id);
        $log($logged->id, $later->id, 'updated');
        $log($systemMade->id, null);
        $log($ghostMade->id, 999999);
        $log($untouched->id, $later->id, 'updated');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('equipment', 'created_by_user_id'));
        $migration->up();

        $creators = DB::table('equipment')->pluck('created_by_user_id', 'id');
        $this->assertSame($creator->id, (int) $creators[$logged->id]);
        $this->assertNull($creators[$systemMade->id]);
        $this->assertNull($creators[$ghostMade->id]);
        $this->assertNull($creators[$untouched->id]);

        $audit = $this->migrationAudits();
        $this->assertCount(1, $audit);
        $this->assertSame('2026_10_26_000003_add_created_by_user_id_to_equipment', $audit[0]['description']);
        $this->assertSame(1, $audit[0]['properties']['equipment_count']);
        $this->assertSame([$creator->id => [$logged->id]], $audit[0]['properties']['equipment_ids_by_user_id']);
    }

    public function test_isp_accounts_get_a_nullable_office(): void
    {
        $migration = $this->migration('2026_10_26_000004_add_office_id_to_isp_accounts');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('isp_accounts', 'office_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('isp_accounts', 'office_id'));
        $this->assertNotNull(Office::factory()->create()->id);
    }

    /**
     * @return array<string, int>
     */
    private function seedViewAllState(): array
    {
        $permission = fn (string $name) => DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web']);
        $role = fn (string $name, string $label, string $scope) => DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'label' => $label, 'data_scope' => $scope]);

        $ids = [
            'sp_view_all' => $permission('stakeholder_profile.view_all'),
            'ic_view_all' => $permission('internet_connectivity.view_all'),
            'sp_view' => $permission('stakeholder_profile.view'),
            'equipment_view' => $permission('equipment.view'),
            'super' => $role('super_administrator', 'Super Administrator', 'all'),
            'system' => $role('system_administrator', 'System Administrator', 'all'),
            'encoder' => $role('encoder', 'Encoder', 'own'),
            'auditor' => $role('auditor', 'Auditor', 'school'),
        ];

        foreach ([[$ids['super'], $ids['sp_view_all']], [$ids['super'], $ids['ic_view_all']], [$ids['super'], $ids['sp_view']],
            [$ids['system'], $ids['sp_view_all']], [$ids['system'], $ids['ic_view_all']],
            [$ids['encoder'], $ids['sp_view']], [$ids['encoder'], $ids['equipment_view']],
            [$ids['auditor'], $ids['ic_view_all']]] as [$roleId, $permissionId]) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $ids['direct_user'] = User::factory()->create()->id;
        DB::table('model_has_permissions')->insert(['permission_id' => $ids['sp_view_all'], 'model_type' => self::USER_MORPH, 'model_id' => $ids['direct_user']]);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function rolePermissions(string $role): array
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    public function test_view_all_is_replaced_by_view_and_edit_and_the_encoder_is_split(): void
    {
        $ids = $this->seedViewAllState();

        $this->migration('2026_10_26_000005_unify_data_scope_permissions_and_roles')->up();

        $this->assertSame(0, DB::table('permissions')->where('name', 'like', '%.view_all')->count());
        $this->assertSame(['internet_connectivity.edit', 'internet_connectivity.view'], $this->rolePermissions('auditor'));
        $this->assertSame('school', DB::table('roles')->where('name', 'auditor')->value('data_scope'));
        $this->assertSame([
            'internet_connectivity.edit', 'internet_connectivity.view', 'stakeholder_profile.edit', 'stakeholder_profile.view',
        ], $this->rolePermissions('system_administrator'));

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_id', $ids['direct_user'])->orderBy('permissions.name')->pluck('permissions.name')->all();
        $this->assertSame(['stakeholder_profile.edit', 'stakeholder_profile.view'], $direct);

        $encoder = DB::table('roles')->where('name', 'encoder')->first();
        $this->assertSame('Division Encoder', $encoder->label);
        $this->assertSame('all', $encoder->data_scope);

        $officeEncoder = DB::table('roles')->where('name', 'office_encoder')->first();
        $this->assertSame('Office Encoder', $officeEncoder->label);
        $this->assertSame('school', $officeEncoder->data_scope);
        $this->assertSame('web', $officeEncoder->guard_name);
        $this->assertSame($this->rolePermissions('encoder'), $this->rolePermissions('office_encoder'));
        $this->assertSame(['equipment.view', 'stakeholder_profile.view'], $this->rolePermissions('office_encoder'));

        $audit = $this->migrationAudits();
        $this->assertCount(1, $audit);
        $properties = $audit[0]['properties'];
        $this->assertSame('2026_10_26_000005_unify_data_scope_permissions_and_roles', $properties['migration']);
        $this->assertSame(['stakeholder_profile.view_all', 'internet_connectivity.view_all'], array_column($properties['view_all_replaced'], 'permission'));
        $this->assertSame(['super_administrator', 'system_administrator'], array_column($properties['view_all_replaced'][0]['roles'], 'name'));
        $this->assertSame([['model_type' => self::USER_MORPH, 'model_id' => $ids['direct_user']]], $properties['view_all_replaced'][0]['direct_holders']);
        $this->assertSame(['super_administrator', 'system_administrator', 'auditor'], array_column($properties['view_all_replaced'][1]['roles'], 'name'));
        $this->assertSame(['label' => 'Encoder', 'description' => null, 'data_scope' => 'own'], $properties['encoder_relabeled']['old']);
        $this->assertSame($officeEncoder->id, $properties['office_encoder_created']['role_id']);
        $this->assertSame(['equipment.view', 'stakeholder_profile.view'], $properties['office_encoder_created']['permissions']);
    }

    public function test_view_all_migration_is_idempotent(): void
    {
        $this->seedViewAllState();
        $migration = $this->migration('2026_10_26_000005_unify_data_scope_permissions_and_roles');

        $migration->up();
        $snapshot = [DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()];
        $migration->up();

        $this->assertSame($snapshot, [DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()]);
        $this->assertSame(1, DB::table('roles')->where('name', 'office_encoder')->count());
        $this->assertCount(1, $this->migrationAudits());
    }

    public function test_view_all_migration_is_a_no_op_on_an_empty_database(): void
    {
        $this->migration('2026_10_26_000005_unify_data_scope_permissions_and_roles')->up();

        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame(0, DB::table('permissions')->count());
        $this->assertSame([], $this->migrationAudits());
    }

    public function test_down_restores_view_all_to_the_admin_roles_and_removes_an_unassigned_office_encoder(): void
    {
        $this->seedViewAllState();
        $migration = $this->migration('2026_10_26_000005_unify_data_scope_permissions_and_roles');
        $migration->up();
        $migration->down();

        foreach (['super_administrator', 'system_administrator'] as $role) {
            $this->assertContains('stakeholder_profile.view_all', $this->rolePermissions($role));
            $this->assertContains('internet_connectivity.view_all', $this->rolePermissions($role));
        }
        $this->assertSame(0, DB::table('roles')->where('name', 'office_encoder')->count());
        $this->assertSame('Encoder', DB::table('roles')->where('name', 'encoder')->value('label'));
    }

    public function test_down_keeps_an_office_encoder_that_has_users(): void
    {
        $this->seedViewAllState();
        $migration = $this->migration('2026_10_26_000005_unify_data_scope_permissions_and_roles');
        $migration->up();

        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', 'office_encoder')->value('id'),
            'model_type' => self::USER_MORPH,
            'model_id' => User::factory()->create()->id,
        ]);
        $migration->down();

        $this->assertSame(1, DB::table('roles')->where('name', 'office_encoder')->count());
    }
}

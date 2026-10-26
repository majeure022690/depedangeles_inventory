<?php

namespace Tests\Feature\Migrations;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RemoveEmailLogManagePermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->migration = require database_path('migrations/2026_10_27_000002_remove_email_log_manage_permission.php');
        $this->migration->down();
    }

    private function permissionId(): ?int
    {
        $id = DB::table('permissions')->where('name', 'email_log.manage')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audits(): array
    {
        return DB::table('activity_log')
            ->where('event', 'system.permission_removed')
            ->get()
            ->map(fn ($row) => json_decode($row->properties, true))
            ->all();
    }

    public function test_down_restores_the_permission_to_the_administrator_roles_only(): void
    {
        $holders = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('permission_id', $this->permissionId())
            ->pluck('roles.name')
            ->all();

        $this->assertEqualsCanonicalizing(['super_administrator', 'system_administrator'], $holders);
    }

    public function test_up_removes_the_permission_and_its_grants_and_audits_who_had_it(): void
    {
        $permissionId = $this->permissionId();
        $custom = Role::create(['name' => 'mail-desk', 'label' => 'Mail desk']);
        DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $custom->id]);
        $direct = User::factory()->create();
        DB::table('model_has_permissions')->insert(['permission_id' => $permissionId, 'model_type' => $direct->getMorphClass(), 'model_id' => $direct->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($direct->fresh()->can('email_log.manage'));

        $this->migration->up();

        $this->assertNull($this->permissionId());
        $this->assertSame(0, DB::table('role_has_permissions')->where('permission_id', $permissionId)->count());
        $this->assertSame(0, DB::table('model_has_permissions')->where('permission_id', $permissionId)->count());
        $this->assertFalse($direct->fresh()->can('email_log.manage'));

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('email_log.manage', $audits[0]['permission']);
        $this->assertEqualsCanonicalizing(
            ['super_administrator', 'system_administrator', 'mail-desk'],
            array_column($audits[0]['roles'], 'name'),
        );
        $this->assertSame([['model_type' => $direct->getMorphClass(), 'model_id' => $direct->id]], $audits[0]['direct_holders']);
    }

    public function test_up_is_a_no_op_when_the_permission_is_already_gone(): void
    {
        $this->migration->up();
        $this->migration->up();

        $this->assertCount(1, $this->audits());
    }

    public function test_round_trip_leaves_one_permission_row(): void
    {
        $this->migration->down();
        $this->assertSame(1, DB::table('permissions')->where('name', 'email_log.manage')->count());

        $this->migration->up();
        $this->migration->down();

        $this->assertSame(1, DB::table('permissions')->where('name', 'email_log.manage')->count());
        $this->assertSame(2, DB::table('role_has_permissions')->where('permission_id', $this->permissionId())->count());
    }
}

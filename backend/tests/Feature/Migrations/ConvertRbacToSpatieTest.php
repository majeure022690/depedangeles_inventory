<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rolls 2026_10_19_000002 back to the hand-rolled RBAC schema, seeds it
 * with non-sequential ids, and re-runs up() to prove ids and grants carry
 * over unchanged (decoupled-spa-stack ADR §7).
 */
class ConvertRbacToSpatieTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_000002_convert_rbac_tables_to_spatie_permission.php');
    }

    /**
     * @return array{alice: int, bob: int}
     */
    private function seedLegacyRbac(): array
    {
        DB::table('permissions')->insert([
            ['id' => 5, 'name' => 'equipment.view', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'name' => 'equipment.transactions.create', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'name' => 'roles.manage', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('roles')->insert([
            ['id' => 3, 'name' => 'encoder', 'label' => 'Encoder', 'description' => 'Data entry', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'admin', 'label' => 'Administrator', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permission_role')->insert([
            ['permission_id' => 5, 'role_id' => 3],
            ['permission_id' => 9, 'role_id' => 3],
            ['permission_id' => 5, 'role_id' => 7],
            ['permission_id' => 12, 'role_id' => 7],
        ]);

        $alice = User::factory()->create()->id;
        $bob = User::factory()->create()->id;

        DB::table('role_user')->insert([
            ['role_id' => 3, 'user_id' => $alice],
            ['role_id' => 7, 'user_id' => $alice],
            ['role_id' => 3, 'user_id' => $bob],
        ]);

        return ['alice' => $alice, 'bob' => $bob];
    }

    public function test_ids_labels_and_grants_are_preserved(): void
    {
        $this->migration()->down();
        $users = $this->seedLegacyRbac();

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('permission_role'));
        $this->assertFalse(Schema::hasTable('role_user'));

        $this->assertSame(
            [[5, 'equipment.view', 'web'], [9, 'equipment.transactions.create', 'web'], [12, 'roles.manage', 'web']],
            DB::table('permissions')->orderBy('id')->get()->map(fn ($p) => [(int) $p->id, $p->name, $p->guard_name])->all(),
        );
        $this->assertSame(
            [[3, 'encoder', 'web', 'Encoder', 'Data entry'], [7, 'admin', 'web', 'Administrator', null]],
            DB::table('roles')->orderBy('id')->get()->map(fn ($r) => [(int) $r->id, $r->name, $r->guard_name, $r->label, $r->description])->all(),
        );

        $this->assertSame(
            ['3:5', '3:9', '7:12', '7:5'],
            DB::table('role_has_permissions')->get()->map(fn ($row) => $row->role_id.':'.$row->permission_id)->sort()->values()->all(),
        );

        $this->assertSame(
            [
                "3:{$users['alice']}:App\\Models\\User",
                "3:{$users['bob']}:App\\Models\\User",
                "7:{$users['alice']}:App\\Models\\User",
            ],
            DB::table('model_has_roles')->get()->map(fn ($row) => "{$row->role_id}:{$row->model_id}:{$row->model_type}")->sort()->values()->all(),
        );
        $this->assertSame(0, DB::table('model_has_permissions')->count());

        $alice = User::findOrFail($users['alice']);
        $this->assertTrue($alice->hasPermissionTo('roles.manage'));
        $this->assertFalse(User::findOrFail($users['bob'])->hasPermissionTo('roles.manage'));
    }

    public function test_unique_index_is_now_scoped_to_guard(): void
    {
        DB::table('permissions')->insert(['name' => 'equipment.view', 'guard_name' => 'web']);
        DB::table('permissions')->insert(['name' => 'equipment.view', 'guard_name' => 'sanctum']);

        $this->expectException(QueryException::class);
        DB::table('permissions')->insert(['name' => 'equipment.view', 'guard_name' => 'web']);
    }

    public function test_down_restores_the_legacy_pivots(): void
    {
        $this->migration()->down();
        $users = $this->seedLegacyRbac();
        $this->migration()->up();

        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('model_has_roles'));
        $this->assertFalse(Schema::hasColumn('roles', 'guard_name'));
        $this->assertSame(4, DB::table('permission_role')->count());
        $this->assertSame(
            ["3:{$users['alice']}", "3:{$users['bob']}", "7:{$users['alice']}"],
            DB::table('role_user')->get()->map(fn ($row) => "{$row->role_id}:{$row->user_id}")->sort()->values()->all(),
        );

        $this->migration()->up();
    }
}

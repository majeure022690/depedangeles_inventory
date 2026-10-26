<?php

namespace Tests\Feature\Migrations;

use App\Models\Office;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddPrimeDepartmentIdToOfficesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_27_000000_add_prime_department_id_to_offices.php');
    }

    public function test_prime_department_id_is_nullable_and_unique(): void
    {
        $unlinked = Office::factory()->create();
        $other = Office::factory()->create();
        $linked = Office::factory()->create();

        $this->assertNull(DB::table('offices')->where('id', $unlinked->id)->value('prime_department_id'));
        DB::table('offices')->where('id', $linked->id)->update(['prime_department_id' => 42]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('offices')->where('id', $other->id)->update(['prime_department_id' => 42]);
    }

    public function test_down_drops_the_column_and_up_restores_it(): void
    {
        $office = Office::factory()->create();
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('offices', 'prime_department_id'));
        $this->assertTrue(DB::table('offices')->where('id', $office->id)->exists());

        $migration->up();
        $this->assertTrue(Schema::hasColumn('offices', 'prime_department_id'));
    }
}

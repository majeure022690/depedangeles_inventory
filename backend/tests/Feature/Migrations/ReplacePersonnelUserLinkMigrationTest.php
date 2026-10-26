<?php

namespace Tests\Feature\Migrations;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReplacePersonnelUserLinkMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_27_000001_replace_personnel_user_link_with_employee_no.php');
        $this->migration->down();
    }

    private function person(string $employeeId, ?int $userId, bool $trashed = false): int
    {
        return DB::table('personnel')->insertGetId([
            'employee_id' => $employeeId, 'last_name' => 'L', 'first_name' => 'F', 'user_id' => $userId,
            'deleted_at' => $trashed ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audits(): array
    {
        return DB::table('activity_log')
            ->where('event', 'system.data_scope_migration')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [...(array) $row, 'properties' => json_decode($row->properties, true)])
            ->all();
    }

    public function test_links_are_copied_to_employee_no_and_the_column_is_dropped(): void
    {
        $linked = User::factory()->create();
        $trashedLink = User::factory()->create();
        $unlinked = User::factory()->create();

        $person = $this->person('  1234567 ', $linked->id);
        $trashed = $this->person('7654321', $trashedLink->id, true);
        $this->person('5555555', null);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('personnel', 'user_id'));
        $numbers = DB::table('users')->pluck('employee_no', 'id');
        $this->assertSame('1234567', $numbers[$linked->id]);
        $this->assertNull($numbers[$trashedLink->id]);
        $this->assertNull($numbers[$unlinked->id]);

        $this->assertSame('L, F - '.DB::table('personnel')->where('id', $person)->value('employee_id'), Personnel::findOrFail($person)->full_name);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('2026_10_27_000001_replace_personnel_user_link_with_employee_no', $audits[0]['description']);
        $this->assertNull($audits[0]['causer_id']);
        $properties = $audits[0]['properties'];
        $this->assertSame('2026_10_27_000001_replace_personnel_user_link_with_employee_no', $properties['migration']);
        $this->assertSame(1, $properties['copied_count']);
        $this->assertSame([['personnel_id' => $person, 'user_id' => $linked->id, 'employee_no' => '1234567']], $properties['copied']);
        $this->assertSame([], $properties['skipped']);
        $this->assertSame([['personnel_id' => $trashed, 'user_id' => $trashedLink->id, 'employee_no' => '7654321']], $properties['dropped_trashed']);
    }

    public function test_employee_ids_that_collide_after_trimming_are_skipped_and_reported(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $blank = User::factory()->create();
        $a = $this->person('1234567', $first->id);
        $b = $this->person(' 1234567', $second->id);
        $c = $this->person('   ', $blank->id);

        $this->migration->up();

        $this->assertSame(0, DB::table('users')->whereNotNull('employee_no')->count());
        $properties = $this->audits()[0]['properties'];
        $this->assertSame(0, $properties['copied_count']);
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_column($properties['skipped'], 'personnel_id'));
    }

    public function test_no_audit_row_when_nothing_was_linked(): void
    {
        User::factory()->create();
        $this->person('1234567', null);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('personnel', 'user_id'));
        $this->assertSame([], $this->audits());
    }

    public function test_employee_no_is_unique(): void
    {
        $this->migration->up();
        User::factory()->withEmployeeNo('1234567')->create();

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->withEmployeeNo('1234567')->create();
    }

    public function test_down_restores_the_link_from_employee_no_for_non_trashed_personnel(): void
    {
        $this->migration->up();

        $matched = User::factory()->withEmployeeNo('1234567')->create();
        $trashedMatch = User::factory()->withEmployeeNo('7654321')->create();
        $noMatch = User::factory()->withEmployeeNo('9999999')->create();
        $person = DB::table('personnel')->insertGetId(['employee_id' => '1234567', 'last_name' => 'L', 'first_name' => 'F', 'created_at' => now(), 'updated_at' => now()]);
        $trashed = DB::table('personnel')->insertGetId(['employee_id' => '7654321', 'last_name' => 'L', 'first_name' => 'F', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('personnel')->insertGetId(['employee_id' => '1111111', 'last_name' => 'L', 'first_name' => 'F', 'created_at' => now(), 'updated_at' => now()]);

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'employee_no'));
        $links = DB::table('personnel')->pluck('user_id', 'id');
        $this->assertSame($matched->id, (int) $links[$person]);
        $this->assertNull($links[$trashed]);
        $this->assertNull($links[$other]);
        $this->assertNotNull($trashedMatch->id);
        $this->assertNotNull($noMatch->id);
    }

    public function test_prime_is_never_queried(): void
    {
        config(['database.connections.external_users' => ['driver' => 'sqlite', 'database' => '/nonexistent/prime.sqlite', 'prefix' => '']]);
        DB::purge('external_users');
        $connections = [];
        DB::listen(function (QueryExecuted $query) use (&$connections) {
            $connections[] = $query->connectionName;
        });

        $this->person('1234567', User::factory()->create()->id);
        $this->migration->up();
        $this->migration->down();

        $this->assertNotContains('external_users', $connections);
    }
}

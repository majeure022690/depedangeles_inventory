<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the audit_logs -> activity_log mapping in
 * decoupled-spa-stack-plan.md (Phase 1, migration 4).
 */
class CopyAuditLogsToActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_19_000004_copy_audit_logs_into_activity_log.php');
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    private function audit(int $id, ?int $actorId, string $action, ?string $subjectType, ?int $subjectId, ?array $properties, string $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'id' => $id,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => $properties === null ? null : json_encode($properties),
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function copied(int $auditId): array
    {
        $row = DB::table('activity_log')
            ->get()
            ->first(fn ($row) => (json_decode($row->properties, true)['migrated_from_audit_log_id'] ?? null) === $auditId);

        $this->assertNotNull($row, "audit_logs row {$auditId} was not copied");

        $row = (array) $row;
        $row['properties'] = json_decode($row['properties'], true);
        $row['attribute_changes'] = $row['attribute_changes'] === null ? null : json_decode($row['attribute_changes'], true);

        return $row;
    }

    public function test_rows_are_mapped_per_the_plan(): void
    {
        $actor = User::factory()->create(['name' => 'Ana Admin', 'email' => 'ana@example.test']);
        $snapshot = ['name' => 'Ana Admin', 'email' => 'ana@example.test'];
        $goneSnapshot = ['name' => 'Deleted User', 'email' => 'gone@example.test'];

        $this->audit(10, $actor->id, 'equipment.updated', 'App\\Models\\Equipment', 4, [
            'changes' => ['remarks' => ['old' => 'a', 'new' => 'b'], 'brand_id' => ['old' => 1, 'new' => 2]],
            'actor_snapshot' => $snapshot,
        ], '2026-07-15 04:45:44');
        $this->audit(11, $actor->id, 'office.created', 'App\\Models\\Office', 9, [
            'attributes' => ['office_name' => 'X School'],
            'actor_snapshot' => $snapshot,
        ], '2026-07-15 05:00:00');
        $this->audit(12, $actor->id, 'reference_data.updated', 'App\\Models\\Brand', 2, [
            'table' => 'brands',
            'before' => ['name' => 'Acer'],
            'after' => ['name' => 'ACER'],
            'actor_snapshot' => $snapshot,
        ], '2026-07-15 06:00:00');
        // Actor account deleted since: FK nulled, snapshot kept.
        $this->audit(13, null, 'personnel.deleted', 'App\\Models\\Personnel', 1, [
            'employee_id' => '9999',
            'actor_snapshot' => $goneSnapshot,
        ], '2026-07-15 07:00:00');
        $this->audit(14, null, 'equipment.transaction.recorded', 'App\\Models\\Equipment', 1, [
            'transaction_id' => 1,
            'transaction_type' => 'Beginning Inventory',
        ], '2026-07-15 08:00:00');
        $this->audit(15, $actor->id, 'authorization.denied', null, null, null, '2026-07-15 09:00:00');

        $this->migration()->up();

        $this->assertSame(6, DB::table('activity_log')->count());

        $updated = $this->copied(10);
        $this->assertSame('default', $updated['log_name']);
        $this->assertSame('updated', $updated['event']);
        $this->assertSame('equipment.updated', $updated['description']);
        $this->assertSame('App\\Models\\Equipment', $updated['subject_type']);
        $this->assertSame(4, (int) $updated['subject_id']);
        $this->assertSame('App\\Models\\User', $updated['causer_type']);
        $this->assertSame($actor->id, (int) $updated['causer_id']);
        $this->assertSame(
            ['old' => ['remarks' => 'a', 'brand_id' => 1], 'attributes' => ['remarks' => 'b', 'brand_id' => 2]],
            $updated['attribute_changes'],
        );
        $this->assertSame(['actor_snapshot' => $snapshot, 'migrated_from_audit_log_id' => 10], $updated['properties']);
        $this->assertStringStartsWith('2026-07-15 04:45:44', (string) $updated['created_at']);
        $this->assertStringStartsWith('2026-07-15 04:45:44', (string) $updated['updated_at']);

        $created = $this->copied(11);
        $this->assertSame('created', $created['event']);
        $this->assertSame(['attributes' => ['office_name' => 'X School']], $created['attribute_changes']);

        $reference = $this->copied(12);
        $this->assertSame('updated', $reference['event']);
        $this->assertSame(['old' => ['name' => 'Acer'], 'attributes' => ['name' => 'ACER']], $reference['attribute_changes']);
        $this->assertSame('brands', $reference['properties']['table']);
        $this->assertArrayNotHasKey('before', $reference['properties']);

        $deleted = $this->copied(13);
        $this->assertSame('deleted', $deleted['event']);
        $this->assertNull($deleted['causer_type']);
        $this->assertNull($deleted['causer_id']);
        $this->assertSame($goneSnapshot, $deleted['properties']['actor_snapshot']);
        $this->assertSame('9999', $deleted['properties']['employee_id']);
        $this->assertNull($deleted['attribute_changes']);

        $transaction = $this->copied(14);
        $this->assertSame('equipment.transaction.recorded', $transaction['event']);
        $this->assertSame('equipment.transaction.recorded', $transaction['description']);
        $this->assertSame('Beginning Inventory', $transaction['properties']['transaction_type']);

        $denied = $this->copied(15);
        $this->assertSame('authorization.denied', $denied['event']);
        $this->assertNull($denied['subject_type']);
        $this->assertSame(['migrated_from_audit_log_id' => 15], $denied['properties']);

        // Source rows are left in place until Phase 7.
        $this->assertSame(6, DB::table('audit_logs')->count());
    }

    public function test_down_removes_only_the_copied_rows(): void
    {
        $this->audit(1, null, 'role.assigned', 'App\\Models\\User', 1, ['role' => 'admin'], '2026-07-15 04:17:51');
        $this->migration()->up();

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'created',
            'event' => 'created',
            'properties' => json_encode(['foo' => 'bar']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->down();

        $this->assertSame(1, DB::table('activity_log')->count());
        $this->assertSame('{"foo":"bar"}', DB::table('activity_log')->value('properties'));
    }
}

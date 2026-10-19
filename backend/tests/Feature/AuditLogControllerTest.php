<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Read-only browse of the activity log (audit_log.view, admin only).
 */
class AuditLogControllerTest extends TestCase
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

    private function record(string $description, ?Model $subject = null, ?User $causer = null, ?Carbon $createdAt = null, ?string $event = null): Activity
    {
        $logger = activity()->event($event ?? $description);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($createdAt !== null) {
            $logger->createdAt($createdAt);
        }

        $activity = $logger->log($description);
        $this->assertInstanceOf(Activity::class, $activity);

        return $activity;
    }

    /**
     * @return list<int>
     */
    private function idsFor(string $query): array
    {
        return collect($this->actingAs($this->admin())->getJson('/api/audit-log'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_admin_can_view_the_audit_log(): void
    {
        $this->actingAs($this->admin())->getJson('/api/audit-log')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links', 'message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['encoder' => ['encoder'], 'viewer' => ['viewer'], 'pending' => ['pending']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->getJson('/api/audit-log')->assertForbidden();
        $this->actingAs($user)->getJson('/api/audit-log/options')->assertForbidden();
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/audit-log')->assertUnauthorized();
    }

    public function test_index_paginates_20_per_page_ordered_newest_first(): void
    {
        $base = Carbon::now();
        for ($i = 0; $i < 25; $i++) {
            $this->record("pagination.test.{$i}", createdAt: $base->copy()->addMinutes($i + 1));
        }

        $response = $this->actingAs($this->admin())->getJson('/api/audit-log?search=pagination.test')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.description', 'pagination.test.24')
            ->assertJsonPath('data.19.description', 'pagination.test.5');

        $this->assertNotContains('pagination.test.4', collect($response->json('data'))->pluck('description')->all());
    }

    public function test_row_shape_includes_changes_properties_and_subject_basename(): void
    {
        $office = Office::create(['office_name' => 'Old Name']);
        $office->update(['office_name' => 'New Name']);
        $activity = Activity::query()->where('subject_type', Office::class)->where('event', 'updated')->sole();

        $row = collect($this->actingAs($this->admin())->getJson('/api/audit-log')->json('data'))->firstWhere('id', $activity->id);

        $this->assertSame('office.updated', $row['description']);
        $this->assertSame('updated', $row['event']);
        $this->assertSame('Office', $row['subject_type']);
        $this->assertSame($office->id, $row['subject_id']);
        $this->assertSame('Old Name', $row['changes']['old']['office_name']);
        $this->assertSame('New Name', $row['changes']['attributes']['office_name']);
        $this->assertArrayHasKey('properties', $row);
        $this->assertArrayHasKey('created_at', $row);
    }

    public function test_search_matches_the_description_substring(): void
    {
        $equipment = $this->record('equipment.created');
        $personnel = $this->record('personnel.updated');

        $ids = $this->idsFor('?search=equipm');
        $this->assertContains($equipment->id, $ids);
        $this->assertNotContains($personnel->id, $ids);
    }

    public function test_search_matches_a_live_actors_name_or_email(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        $log = $this->record('alice.action', causer: $alice);

        $this->assertContains($log->id, $this->idsFor('?search='.urlencode('Alice Smith')));
        $this->assertContains($log->id, $this->idsFor('?search=alice@example.com'));
    }

    public function test_the_actor_snapshot_survives_the_actor_account_being_deleted(): void
    {
        $bob = User::factory()->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);
        $log = $this->record('deleted.actor.action', causer: $bob);
        $bobId = $bob->id;

        $bob->delete();

        $response = $this->actingAs($this->admin())->getJson('/api/audit-log?search='.urlencode('Bob Jones'))->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $log->id);

        $this->assertNotNull($row, 'search by the deleted actor\'s snapshotted name should still find the row');
        $this->assertSame(['id' => $bobId, 'name' => 'Bob Jones', 'email' => 'bob@example.com'], $row['actor']);
        $this->assertContains($log->id, $this->idsFor('?search=bob@example.com'));
    }

    public function test_actor_resolves_to_the_live_user_when_the_account_still_exists(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        $log = $this->record('actor.live.action', causer: $alice);
        $alice->update(['name' => 'Alice Renamed']);

        $row = collect($this->actingAs($this->admin())->getJson('/api/audit-log?search=actor.live.action')->json('data'))->firstWhere('id', $log->id);

        $this->assertSame(['id' => $alice->id, 'name' => 'Alice Renamed', 'email' => 'alice@example.com'], $row['actor']);
    }

    public function test_actor_is_null_when_there_is_no_actor_and_no_snapshot(): void
    {
        $log = $this->record('system.console.action');

        $row = collect($this->actingAs($this->admin())->getJson('/api/audit-log?search=system.console.action')->json('data'))->firstWhere('id', $log->id);

        $this->assertNotNull($row);
        $this->assertNull($row['actor']);
    }

    public function test_event_subject_type_and_causer_filters_are_exact_matches(): void
    {
        $office = Office::create(['office_name' => 'Test Elementary School']);
        $alice = User::factory()->create();
        $exact = $this->record('equipment.created', causer: $alice);
        $longer = $this->record('equipment.created.extra');
        $officeLog = $this->record('office.probe', $office);

        $ids = $this->idsFor('?event=equipment.created');
        $this->assertContains($exact->id, $ids);
        $this->assertNotContains($longer->id, $ids);

        $this->assertContains($officeLog->id, $this->idsFor('?subject_type='.urlencode(Office::class)));
        $this->assertNotContains($officeLog->id, $this->idsFor('?subject_type=Office'));

        $this->assertSame([$exact->id], $this->idsFor("?causer_id={$alice->id}"));
    }

    public function test_date_range_is_inclusive_on_both_boundaries(): void
    {
        $before = $this->record('range.before', createdAt: Carbon::parse('2026-01-01 10:00:00'));
        $fromBoundary = $this->record('range.from-boundary', createdAt: Carbon::parse('2026-01-05 00:00:00'));
        $middle = $this->record('range.middle', createdAt: Carbon::parse('2026-01-10 12:00:00'));
        $toBoundary = $this->record('range.to-boundary', createdAt: Carbon::parse('2026-01-15 23:59:59'));
        $after = $this->record('range.after', createdAt: Carbon::parse('2026-01-20 00:00:00'));

        $ids = $this->idsFor('?date_from=2026-01-05&date_to=2026-01-15');

        $this->assertContains($fromBoundary->id, $ids);
        $this->assertContains($middle->id, $ids);
        $this->assertContains($toBoundary->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_options_reflect_the_distinct_events_and_subject_types_present(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Test Elementary School']);
        $this->record('user.probe', $admin);
        $this->record('zzz.last.action');
        $this->record('aaa.first.action', $office);

        $response = $this->actingAs($admin)->getJson('/api/audit-log/options')->assertOk();

        $events = $response->json('data.events');
        $this->assertContains('zzz.last.action', $events);
        $this->assertContains('aaa.first.action', $events);
        $this->assertContains('created', $events);
        $sorted = $events;
        sort($sorted);
        $this->assertSame($sorted, $events);

        $subjectTypes = collect($response->json('data.subject_types'));
        $this->assertSame(['value' => Office::class, 'label' => 'Office'], $subjectTypes->firstWhere('value', Office::class));
        $this->assertContains(User::class, $subjectTypes->pluck('value')->all());
    }

    public function test_the_audit_log_cannot_be_written_through_the_api(): void
    {
        $admin = $this->admin();
        $log = $this->record('immutable.row');

        $this->actingAs($admin)->postJson('/api/audit-log', [])->assertStatus(405);
        $this->actingAs($admin)->putJson("/api/audit-log/{$log->id}", [])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/audit-log/{$log->id}")->assertNotFound();

        $this->assertNotNull(Activity::find($log->id));
    }
}

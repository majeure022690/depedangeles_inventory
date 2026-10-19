<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Coverage for the audit_log.view admin screen: a single-action, read-only,
 * paginated browse of the append-only `audit_logs` table (see
 * AuditLogController's own doc-comment — no create/store/edit/update/
 * destroy routes exist or should exist for this resource).
 *
 * Note: User::assignRole() itself writes a 'role.assigned' AuditLog row
 * (see User::assignRole()'s doc-comment), so every helper below that
 * assigns a role to a freshly-created test user (admin()/encoder()/etc.)
 * incidentally adds one row to the table. Tests that care about exact
 * counts/ordering account for that row explicitly rather than assuming an
 * empty table.
 */
class AuditLogControllerTest extends TestCase
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

    /**
     * Thin wrapper over AuditLog::record() that additionally lets a test
     * backdate/forward-date the row (record() always hardcodes
     * `created_at` to `now()`), needed for pagination-order and
     * date-range-filter coverage.
     *
     * @param  array<string, mixed>  $properties
     */
    private function recordAt(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        ?int $actorId = null,
        ?Carbon $createdAt = null,
    ): AuditLog {
        $log = AuditLog::record($action, $subject, $properties, $actorId);

        if ($createdAt !== null) {
            $log->created_at = $createdAt;
            $log->save();
        }

        return $log;
    }

    // ------------------------------------------------------------------
    // 1. Authorization
    // ------------------------------------------------------------------

    public function test_admin_can_view_the_audit_log(): void
    {
        $response = $this->actingAs($this->admin())->get(route('audit-log.index'));

        $response->assertOk();
        $this->assertSame('audit-log/Index', $response->inertiaPage()['component']);
    }

    public function test_encoder_is_forbidden(): void
    {
        $this->actingAs($this->encoder())
            ->get(route('audit-log.index'))
            ->assertForbidden();
    }

    public function test_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('audit-log.index'))
            ->assertForbidden();
    }

    public function test_pending_is_forbidden(): void
    {
        $this->actingAs($this->pending())
            ->get(route('audit-log.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('audit-log.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // 2. Listing / pagination
    // ------------------------------------------------------------------

    public function test_index_paginates_20_per_page_ordered_newest_first(): void
    {
        // Creating the admin itself writes one 'role.assigned' row (actor
        // null — nobody is authenticated yet at this point in the test),
        // timestamped at real "now". Every explicitly-seeded row below is
        // deliberately timestamped strictly after that, so it doesn't
        // interfere with the "top 20 are my newest 20" assertion.
        $admin = $this->admin();

        $base = Carbon::now();
        for ($i = 0; $i < 25; $i++) {
            $this->recordAt("pagination.test.{$i}", createdAt: $base->copy()->addMinutes($i + 1));
        }

        $page = $this->actingAs($admin)->get(route('audit-log.index'))->inertiaPage();

        $this->assertSame(26, $page['props']['logs']['total']);
        $this->assertCount(20, $page['props']['logs']['data']);

        // Newest first: pagination.test.24 (created latest) leads page 1;
        // pagination.test.5 is the 20th (oldest of the top 20).
        $this->assertSame('pagination.test.24', $page['props']['logs']['data'][0]['action']);
        $this->assertSame('pagination.test.5', $page['props']['logs']['data'][19]['action']);

        $ids = collect($page['props']['logs']['data'])->pluck('action')->all();
        $this->assertNotContains('pagination.test.4', $ids);
        $this->assertNotContains('role.assigned', $ids);
    }

    // ------------------------------------------------------------------
    // 3. `search` filter
    // ------------------------------------------------------------------

    public function test_search_matches_the_action_substring(): void
    {
        $admin = $this->admin();
        $this->recordAt('equipment.created');
        $this->recordAt('personnel.updated');

        $page = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'equipm']))
            ->inertiaPage();

        $actions = collect($page['props']['logs']['data'])->pluck('action')->all();
        $this->assertContains('equipment.created', $actions);
        $this->assertNotContains('personnel.updated', $actions);
    }

    public function test_search_matches_a_live_actors_name_or_email(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        $log = $this->recordAt('alice.action', actorId: $alice->id);

        $byName = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'Alice Smith']))
            ->inertiaPage();
        $this->assertContains($log->id, collect($byName['props']['logs']['data'])->pluck('id')->all());

        $byEmail = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'alice@example.com']))
            ->inertiaPage();
        $this->assertContains($log->id, collect($byEmail['props']['logs']['data'])->pluck('id')->all());
    }

    /**
     * The trickiest case: a search for the NAME/EMAIL of an actor whose
     * User account has since been hard-deleted must still find the row,
     * matched via the properties->actor_snapshot JSON path rather than the
     * (now nulled-out, via nullOnDelete) live actor relation.
     */
    public function test_search_matches_the_actor_snapshot_when_the_actor_account_was_deleted(): void
    {
        $admin = $this->admin();
        $bob = User::factory()->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);
        $log = $this->recordAt('deleted.actor.action', actorId: $bob->id);

        $bob->delete();

        // Confirm the premise: actor_id genuinely nulled out via the FK's
        // nullOnDelete, so any match below can only be coming from the
        // JSON snapshot, not the live relation.
        $this->assertNull($log->fresh()->actor_id);

        $byName = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'Bob Jones']))
            ->inertiaPage();
        $rowByName = collect($byName['props']['logs']['data'])->firstWhere('id', $log->id);
        $this->assertNotNull($rowByName, 'search by the deleted actor\'s snapshotted name should still find the row');
        $this->assertSame(['id' => null, 'name' => 'Bob Jones', 'email' => 'bob@example.com'], $rowByName['actor']);

        $byEmail = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'bob@example.com']))
            ->inertiaPage();
        $this->assertNotNull(collect($byEmail['props']['logs']['data'])->firstWhere('id', $log->id));
    }

    // ------------------------------------------------------------------
    // 4. `action` / `subject_type` filters
    // ------------------------------------------------------------------

    public function test_action_filter_is_an_exact_match(): void
    {
        $admin = $this->admin();
        $this->recordAt('equipment.created');
        $this->recordAt('equipment.created.extra');

        $page = $this->actingAs($admin)
            ->get(route('audit-log.index', ['action' => 'equipment.created']))
            ->inertiaPage();

        $actions = collect($page['props']['logs']['data'])->pluck('action')->all();
        $this->assertContains('equipment.created', $actions);
        $this->assertNotContains('equipment.created.extra', $actions);
    }

    public function test_subject_type_filter_requires_the_full_class_name(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Test Elementary School']);
        $log = $this->recordAt('office.created', $office);

        $fqcnPage = $this->actingAs($admin)
            ->get(route('audit-log.index', ['subject_type' => Office::class]))
            ->inertiaPage();
        $this->assertContains($log->id, collect($fqcnPage['props']['logs']['data'])->pluck('id')->all());

        $row = collect($fqcnPage['props']['logs']['data'])->firstWhere('id', $log->id);
        $this->assertSame('Office', $row['subject_type']);

        // The short class basename alone must NOT match — the controller
        // filters on the raw `subject_type` column, which stores the full
        // FQCN, not the basename used for display.
        $basenamePage = $this->actingAs($admin)
            ->get(route('audit-log.index', ['subject_type' => 'Office']))
            ->inertiaPage();
        $this->assertNotContains($log->id, collect($basenamePage['props']['logs']['data'])->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // 5. `from` / `to` date range
    // ------------------------------------------------------------------

    public function test_from_to_date_range_is_inclusive_on_both_boundaries(): void
    {
        $admin = $this->admin();

        $before = $this->recordAt('range.before', createdAt: Carbon::parse('2026-01-01 10:00:00'));
        $fromBoundary = $this->recordAt('range.from-boundary', createdAt: Carbon::parse('2026-01-05 00:00:00'));
        $middle = $this->recordAt('range.middle', createdAt: Carbon::parse('2026-01-10 12:00:00'));
        $toBoundary = $this->recordAt('range.to-boundary', createdAt: Carbon::parse('2026-01-15 23:59:59'));
        $after = $this->recordAt('range.after', createdAt: Carbon::parse('2026-01-20 00:00:00'));

        $page = $this->actingAs($admin)->get(route('audit-log.index', [
            'from' => '2026-01-05',
            'to' => '2026-01-15',
        ]))->inertiaPage();

        $ids = collect($page['props']['logs']['data'])->pluck('id')->all();
        $this->assertContains($fromBoundary->id, $ids);
        $this->assertContains($middle->id, $ids);
        $this->assertContains($toBoundary->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    // ------------------------------------------------------------------
    // 6. `actor` resolution correctness
    // ------------------------------------------------------------------

    public function test_actor_resolves_to_the_live_user_when_the_account_still_exists(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        $log = $this->recordAt('actor.live.action', actorId: $alice->id);

        $page = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'actor.live.action']))
            ->inertiaPage();
        $row = collect($page['props']['logs']['data'])->firstWhere('id', $log->id);

        $this->assertSame(
            ['id' => $alice->id, 'name' => 'Alice Smith', 'email' => 'alice@example.com'],
            $row['actor']
        );
    }

    // The snapshot-fallback case is exercised end-to-end by
    // test_search_matches_the_actor_snapshot_when_the_actor_account_was_deleted()
    // above, which already asserts the resolved `actor` shape
    // (`{id: null, name, email}`) for a deleted actor.

    public function test_actor_is_null_when_there_is_no_actor_and_no_snapshot(): void
    {
        // No authenticated user in this test at the point of recording, and
        // no explicit actorId passed — genuinely system/console-initiated.
        $log = AuditLog::record('system.console.action');
        $this->assertNull($log->actor_id);
        $this->assertArrayNotHasKey('actor_snapshot', $log->properties ?? []);

        $admin = $this->admin();
        $page = $this->actingAs($admin)
            ->get(route('audit-log.index', ['search' => 'system.console.action']))
            ->inertiaPage();
        $row = collect($page['props']['logs']['data'])->firstWhere('id', $log->id);

        $this->assertNotNull($row);
        $this->assertNull($row['actor']);
    }

    // ------------------------------------------------------------------
    // 7. `filterOptions`
    // ------------------------------------------------------------------

    public function test_filter_options_reflect_the_distinct_actions_and_subject_types_present(): void
    {
        $admin = $this->admin(); // also contributes 'role.assigned' / App\Models\User

        $office = Office::create(['office_name' => 'Test Elementary School']);
        $this->recordAt('zzz.last.action');
        $this->recordAt('aaa.first.action', $office);

        $page = $this->actingAs($admin)->get(route('audit-log.index'))->inertiaPage();

        $actions = $page['props']['filterOptions']['actions'];
        $this->assertContains('zzz.last.action', $actions);
        $this->assertContains('aaa.first.action', $actions);
        $this->assertContains('role.assigned', $actions);
        $sortedActions = $actions;
        sort($sortedActions);
        $this->assertSame($sortedActions, $actions);

        $subjectTypes = $page['props']['filterOptions']['subjectTypes'];
        $values = collect($subjectTypes)->pluck('value')->all();
        $this->assertContains(Office::class, $values);
        $this->assertContains(User::class, $values);

        $officeOption = collect($subjectTypes)->firstWhere('value', Office::class);
        $this->assertSame('Office', $officeOption['label']);

        $sortedValues = $values;
        sort($sortedValues);
        $this->assertSame($sortedValues, $values);
    }

    // ------------------------------------------------------------------
    // 8. No mutation routes exist for this resource
    // ------------------------------------------------------------------

    public function test_only_the_index_route_is_registered_for_audit_log(): void
    {
        $this->assertTrue(Route::has('audit-log.index'));
        $this->assertFalse(Route::has('audit-log.store'));
        $this->assertFalse(Route::has('audit-log.create'));
        $this->assertFalse(Route::has('audit-log.edit'));
        $this->assertFalse(Route::has('audit-log.update'));
        $this->assertFalse(Route::has('audit-log.destroy'));
        $this->assertFalse(Route::has('audit-log.show'));
    }

    public function test_posting_to_the_audit_log_uri_is_not_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post('/audit-log')
            ->assertStatus(405);
    }
}

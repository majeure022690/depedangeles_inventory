<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Coverage for the users.manage admin screen closing the tinker-only gap
 * flagged by the 2026-07 security review, now generalized to real RBAC:
 * a user can hold more than one role at once (role_user is a genuine
 * many-to-many pivot), so UserRoleService::syncRoles() replaces a user's
 * entire role set in one call rather than picking a single role from a
 * dropdown. Its two forward-looking guards (self-escalation, last-admin
 * lockout) are exercised end-to-end under this multi-role contract.
 *
 * Note on the last-admin lockout guard: because update() requires the
 * *actor* to already hold users.manage, and self-changes are blocked
 * separately, any HTTP call that demotes a users.manage holder is always
 * made by a distinct user who also holds users.manage — meaning the acting
 * user is themselves always "another remaining holder." The lockout guard
 * therefore can never actually be triggered through the HTTP route as it
 * exists today; it exists in UserRoleService (the sanctioned entry point)
 * as a defensive invariant for any caller, HTTP or otherwise, per the
 * security review's literal requirement. It's verified directly against
 * the Service below, and via HTTP for the "another admin remains" case
 * that *is* reachable.
 */
class UserControllerTest extends TestCase
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
        $user->assignRole('division-ict-admin');

        return $user;
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    /**
     * A users.manage holder whose OWN permission set is everything EXCEPT
     * roles.manage — i.e. a realistic "ops admin" who can genuinely
     * manage day-to-day operational roles/users but was never trusted
     * with redefining what roles mean. Used to exercise
     * UserRoleService::guardAgainstPermissionTierViolation() (CRITICAL,
     * 2026-07 follow-up security review): this actor must be able to
     * assign roles within their own ceiling (viewer/encoder) but never a
     * role — like division-ict-admin — that carries roles.manage.
     */
    private function actorHoldingAllPermissionsExceptRolesManage(): User
    {
        $role = Role::create(['name' => 'ops-admin', 'label' => 'Ops Admin']);
        $role->permissions()->sync(
            Permission::whereIn('name', collect(PermissionEnum::cases())
                ->reject(fn (PermissionEnum $case) => $case === PermissionEnum::RolesManage)
                ->map(fn (PermissionEnum $case) => $case->value)
                ->all())
                ->pluck('id'),
        );

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * users/Index.vue doesn't exist yet (Frontend builds it against the
     * prop contract this controller establishes), so every GET here uses
     * the X-Inertia header to simulate a client-side Inertia navigation —
     * that path returns pure JSON straight from the controller and never
     * touches the root Blade view's `@vite(...)` call, which is the only
     * place a missing compiled Vue chunk would blow up. A real *first*
     * page load (no X-Inertia header) still needs the compiled asset to
     * exist, same as any other Inertia page.
     */
    private function inertiaGet(string $url): TestResponse
    {
        // The X-Inertia-Version header must match the server's computed
        // asset version (a hash of the whole build manifest — see
        // Inertia\Middleware::version()) or Inertia responds 409 to force
        // a full reload. The hash covers the manifest file as a whole,
        // not any single page's entry, so this works even though
        // users/Index.vue itself isn't in it yet.
        $manifest = public_path('build/manifest.json');
        $version = file_exists($manifest) ? hash_file('xxh128', $manifest) : null;

        return $this->get($url, array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]));
    }

    public function test_index_is_forbidden_without_users_manage(): void
    {
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user);
        $this->inertiaGet(route('users.index'))->assertForbidden();
    }

    public function test_update_is_forbidden_without_users_manage(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('encoder');

        $target = User::factory()->create();
        $target->assignRole('pending');

        $this->actingAs($actor)
            ->patch(route('users.update', $target), ['role_ids' => [$this->roleId('viewer')]])
            ->assertForbidden();

        $this->assertSame(['pending'], $target->fresh()->roles()->pluck('name')->all());
    }

    /**
     * assertInertia() only works against a full Blade-view page load: it
     * calls assertViewHas('page') under the hood, but a request carrying
     * X-Inertia:true (what inertiaGet() sends, precisely to avoid needing
     * the not-yet-built users/Index.vue compiled asset) makes Laravel's
     * Inertia middleware return raw JSON directly, bypassing the view
     * entirely — so assertViewHas('page') has nothing to find and
     * assertInertia() always fails with "Not a valid Inertia response",
     * regardless of whether the response is actually correct. Decode the
     * JSON body directly instead, same as inertiaGet()'s own doc-comment
     * already established as the pattern for this class.
     */
    private function assertInertiaJson(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode($response->getContent(), true);
    }

    public function test_index_lists_users_with_roles_and_supports_search_and_role_filter(): void
    {
        $admin = $this->admin();

        $encoder = User::factory()->create(['name' => 'Encoder Person', 'email' => 'encoder@example.com']);
        $encoder->assignRole('encoder');

        $viewer = User::factory()->create(['name' => 'Viewer Person', 'email' => 'viewer@example.com']);
        $viewer->assignRole('viewer');

        $this->actingAs($admin);

        $page = $this->assertInertiaJson($this->inertiaGet(route('users.index')));
        $this->assertSame('users/Index', $page['component']);
        $this->assertCount(3, $page['props']['users']['data']);
        $this->assertArrayHasKey('roles', $page['props']);

        $searchPage = $this->assertInertiaJson($this->inertiaGet(route('users.index', ['search' => 'Encoder'])));
        $this->assertCount(1, $searchPage['props']['users']['data']);

        $rolePage = $this->assertInertiaJson($this->inertiaGet(route('users.index', ['role' => 'viewer'])));
        $this->assertCount(1, $rolePage['props']['users']['data']);
    }

    public function test_admin_can_change_another_users_role_and_it_is_audited(): void
    {
        $admin = $this->admin();

        $pendingUser = User::factory()->create(['name' => 'New Registrant', 'email' => 'pending@example.com']);
        $pendingUser->assignRole('pending');

        $response = $this->actingAs($admin)->patch(route('users.update', $pendingUser), [
            'role_ids' => [$this->roleId('encoder')],
        ]);

        $response->assertRedirect(route('users.index'));

        $pendingUser->refresh();
        $this->assertSame(['encoder'], $pendingUser->roles()->pluck('name')->all());
        $this->assertSame([
            'personnel.view', 'personnel.create', 'personnel.edit',
            'equipment.view', 'equipment.create', 'equipment.edit',
            'equipment.transactions.create',
            'isp_accounts.view', 'isp_accounts.create', 'isp_accounts.edit',
            'stakeholder_profile.view', 'stakeholder_profile.edit',
            'internet_connectivity.view', 'internet_connectivity.edit',
        ], $pendingUser->permissionNames());

        $log = AuditLog::where('action', 'user.roles_changed')
            ->where('subject_id', $pendingUser->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['pending'], $log->properties['previous_roles']);
        $this->assertSame(['encoder'], $log->properties['new_roles']);
    }

    public function test_admin_can_assign_multiple_roles_to_a_user_and_permissions_union(): void
    {
        $admin = $this->admin();

        $target = User::factory()->create();
        $target->assignRole('viewer');

        $this->actingAs($admin)->patch(route('users.update', $target), [
            'role_ids' => [$this->roleId('viewer'), $this->roleId('encoder')],
        ])->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame(['encoder', 'viewer'], $target->roles()->pluck('name')->sort()->values()->all());

        // Effective permissions are the UNION of both roles — e.g.
        // equipment.delete is in neither, but equipment.create is only
        // on encoder and equipment.view is on both (deduplicated).
        $permissions = $target->permissionNames();
        $this->assertContains('equipment.view', $permissions);
        $this->assertContains('equipment.create', $permissions);
        $this->assertContains('personnel.view', $permissions);
        $this->assertNotContains('equipment.delete', $permissions);
        $this->assertSame(count($permissions), count(array_unique($permissions)));
    }

    public function test_admin_can_strip_all_roles_from_a_user(): void
    {
        $admin = $this->admin();

        $target = User::factory()->create();
        $target->assignRole('viewer');

        $this->actingAs($admin)->patch(route('users.update', $target), [
            'role_ids' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertSame([], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_role_ids_must_reference_existing_roles(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->patch(route('users.update', $target), [
            'role_ids' => [999999],
        ])->assertSessionHasErrors(['role_ids.0']);
    }

    public function test_user_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->patch(route('users.update', $admin), [
            'role_ids' => [$this->roleId('viewer')],
        ]);

        $response->assertSessionHasErrors(['role_ids']);
        $this->assertSame(['division-ict-admin'], $admin->fresh()->roles()->pluck('name')->all());
        $this->assertNull(AuditLog::where('action', 'user.roles_changed')->first());
    }

    public function test_demoting_an_admin_succeeds_when_another_admin_remains(): void
    {
        $actingAdmin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actingAs($actingAdmin)
            ->patch(route('users.update', $otherAdmin), ['role_ids' => [$this->roleId('viewer')]])
            ->assertRedirect(route('users.index'));

        $this->assertSame(['viewer'], $otherAdmin->fresh()->roles()->pluck('name')->all());
    }

    public function test_service_blocks_demoting_the_sole_remaining_users_manage_holder(): void
    {
        $soleAdmin = $this->admin();
        $viewerRole = Role::where('name', 'viewer')->firstOrFail();

        // A distinct actor is required to reach this branch at all (the
        // self-escalation guard blocks soleAdmin acting on themselves),
        // but no *other* users.manage holder exists in this dataset, so
        // the lockout guard must reject the change regardless of who the
        // actor is.
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleService::class)->syncRoles($actor, $soleAdmin, [$viewerRole->id]);
        } finally {
            $this->assertSame(
                ['division-ict-admin'],
                $soleAdmin->fresh()->roles()->pluck('name')->all(),
            );
            $this->assertNull(AuditLog::where('action', 'user.roles_changed')->first());
        }
    }

    public function test_service_no_op_sync_does_not_write_an_audit_log(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $target->assignRole('viewer');

        app(UserRoleService::class)->syncRoles($admin, $target, [$this->roleId('viewer')]);

        $this->assertNull(AuditLog::where('action', 'user.roles_changed')->first());
    }

    public function test_demoting_sole_admin_succeeds_when_new_role_set_still_grants_users_manage(): void
    {
        // Sole admin is synced to a set that includes division-ict-admin
        // ALONGSIDE viewer — the union check must see that at least one
        // role in the new set still grants users.manage, so this is not
        // a lockout even though 'viewer' alone would not grant it.
        $soleAdmin = $this->admin();
        $actor = User::factory()->create();
        $actor->assignRole('division-ict-admin');

        app(UserRoleService::class)->syncRoles($actor, $soleAdmin, [
            $this->roleId('division-ict-admin'),
            $this->roleId('viewer'),
        ]);

        $this->assertSame(
            ['division-ict-admin', 'viewer'],
            $soleAdmin->fresh()->roles()->pluck('name')->sort()->values()->all(),
        );
        $this->assertTrue($soleAdmin->fresh()->hasPermissionTo('users.manage'));
    }

    // --- Permission-tier separation guard (CRITICAL, 2026-07 follow-up review) ---

    public function test_users_manage_only_actor_cannot_grant_a_role_containing_roles_manage(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = User::factory()->create();
        $target->assignRole('viewer');

        $response = $this->actingAs($actor)->patch(route('users.update', $target), [
            'role_ids' => [$this->roleId('division-ict-admin')],
        ]);

        $response->assertSessionHasErrors(['role_ids']);
        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertNull(AuditLog::where('action', 'user.roles_changed')->first());
    }

    public function test_service_blocks_permission_tier_violation_naming_the_role_and_permission(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = User::factory()->create();

        try {
            app(UserRoleService::class)->syncRoles($actor, $target, [$this->roleId('division-ict-admin')]);
            $this->fail('Expected a ValidationException naming the offending role/permission.');
        } catch (ValidationException $e) {
            $message = $e->errors()['role_ids'][0];
            $this->assertStringContainsString('Division ICT Admin', $message);
            $this->assertStringContainsString(PermissionEnum::RolesManage->value, $message);
        }
    }

    public function test_users_manage_only_actor_can_still_assign_roles_within_their_own_permission_ceiling(): void
    {
        // The legitimate, intended use case: this actor's ceiling covers
        // every permission encoder/viewer grant (just not roles.manage),
        // so ordinary user-management keeps working unimpeded.
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = User::factory()->create();
        $target->assignRole('pending');

        $this->actingAs($actor)->patch(route('users.update', $target), [
            'role_ids' => [$this->roleId('encoder')],
        ])->assertRedirect(route('users.index'));

        $this->assertSame(['encoder'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($actor)->patch(route('users.update', $target), [
            'role_ids' => [$this->roleId('viewer')],
        ])->assertRedirect(route('users.index'));

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_division_ict_admin_can_still_assign_any_role_including_itself_to_others(): void
    {
        // division-ict-admin holds every permission, so every role —
        // including division-ict-admin itself — must remain assignable
        // to them with no special-casing (it falls out of the "subset of
        // actor's own permissions" rule naturally).
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->patch(route('users.update', $target), [
            'role_ids' => [$this->roleId('division-ict-admin')],
        ])->assertRedirect(route('users.index'));

        $this->assertSame(['division-ict-admin'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertTrue($target->fresh()->hasPermissionTo(PermissionEnum::RolesManage));
    }

    public function test_index_roles_prop_excludes_roles_the_actor_cannot_assign(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();

        $this->actingAs($actor);
        $page = $this->assertInertiaJson($this->inertiaGet(route('users.index')));

        $roleNames = collect($page['props']['roles'])->pluck('name')->all();
        $this->assertContains('viewer', $roleNames);
        $this->assertContains('encoder', $roleNames);
        $this->assertContains('ops-admin', $roleNames);
        $this->assertNotContains('division-ict-admin', $roleNames);
    }

    public function test_index_roles_prop_includes_every_role_for_division_ict_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        $page = $this->assertInertiaJson($this->inertiaGet(route('users.index')));

        $roleNames = collect($page['props']['roles'])->pluck('name')->sort()->values()->all();
        $allRoleNames = Role::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame($allRoleNames, $roleNames);
    }
}

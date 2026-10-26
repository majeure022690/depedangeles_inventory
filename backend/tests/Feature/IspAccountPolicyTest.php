<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\IspAccount;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Locks in the ISP permission-scoping decision documented on
 * Permission::IspAccountsEdit and IspAccountPolicy::update(): speed-test/
 * subscription-cost logging is authorized against the parent IspAccount via
 * the ordinary `update` ability, not a distinct permission — mirroring
 * verification already done manually via tinker.
 *
 * No reference-data seeding needed: policy checks here don't touch any
 * lookup-backed field, and IspAccountFactory (lookup-normalization ADR,
 * Step 2+3) no longer queries the `lookups` table at all — it uses plain
 * literal strings for its Tier 2 fields and leaves isp_provider_id
 * (Tier 1) null by default, which is fine for a plain policy check.
 */
class IspAccountPolicyTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private function makeIspAccount(): IspAccount
    {
        return IspAccount::factory()->create();
    }

    public function test_user_without_isp_permissions_has_no_isp_account_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $outsider = $this->userWithAllPermissions([
            Permission::IspAccountsView, Permission::IspAccountsCreate, Permission::IspAccountsEdit, Permission::IspAccountsDelete,
        ]);
        $account = $this->makeIspAccount();

        $this->assertFalse($outsider->can('viewAny', IspAccount::class));
        $this->assertFalse($outsider->can('view', $account));
        $this->assertFalse($outsider->can('create', IspAccount::class));
        $this->assertFalse($outsider->can('update', $account));
        $this->assertFalse($outsider->can('delete', $account));
    }

    public function test_view_create_edit_holder_can_do_those_but_not_delete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $editor = $this->userWithPermissions([Permission::IspAccountsView, Permission::IspAccountsCreate, Permission::IspAccountsEdit]);
        $account = $this->makeIspAccount();

        $this->assertTrue($editor->can('viewAny', IspAccount::class));
        $this->assertTrue($editor->can('create', IspAccount::class));
        // Also gates logging a speed test / subscription cost entry
        // against this account — see class doc-comment.
        $this->assertTrue($editor->can('update', $account));
        $this->assertFalse($editor->can('delete', $account));
    }

    public function test_holder_of_every_isp_permission_can_do_everything_including_delete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->userWithPermissions([
            Permission::IspAccountsView, Permission::IspAccountsCreate, Permission::IspAccountsEdit, Permission::IspAccountsDelete,
        ]);
        $account = $this->makeIspAccount();

        $this->assertTrue($admin->can('create', IspAccount::class));
        $this->assertTrue($admin->can('update', $account));
        $this->assertTrue($admin->can('delete', $account));
    }
}

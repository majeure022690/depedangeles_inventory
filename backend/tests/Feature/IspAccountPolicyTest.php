<?php

namespace Tests\Feature;

use App\Models\IspAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    private function makeIspAccount(): IspAccount
    {
        return IspAccount::factory()->create();
    }

    public function test_viewer_can_view_but_not_create_or_edit_or_delete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $account = $this->makeIspAccount();

        $this->assertTrue($viewer->can('viewAny', IspAccount::class));
        $this->assertTrue($viewer->can('view', $account));
        $this->assertFalse($viewer->can('create', IspAccount::class));
        $this->assertFalse($viewer->can('update', $account));
        $this->assertFalse($viewer->can('delete', $account));
    }

    public function test_encoder_can_view_create_and_edit_but_not_delete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $encoder = User::factory()->create();
        $encoder->assignRole('encoder');
        $account = $this->makeIspAccount();

        $this->assertTrue($encoder->can('viewAny', IspAccount::class));
        $this->assertTrue($encoder->can('create', IspAccount::class));
        // Also gates logging a speed test / subscription cost entry
        // against this account — see class doc-comment.
        $this->assertTrue($encoder->can('update', $account));
        $this->assertFalse($encoder->can('delete', $account));
    }

    public function test_division_ict_admin_can_do_everything_including_delete(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $account = $this->makeIspAccount();

        $this->assertTrue($admin->can('create', IspAccount::class));
        $this->assertTrue($admin->can('update', $account));
        $this->assertTrue($admin->can('delete', $account));
    }

    public function test_pending_user_can_do_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $pending = User::factory()->create();
        $pending->assignRole('pending');
        $account = $this->makeIspAccount();

        $this->assertFalse($pending->can('viewAny', IspAccount::class));
        $this->assertFalse($pending->can('view', $account));
        $this->assertFalse($pending->can('create', IspAccount::class));
        $this->assertFalse($pending->can('update', $account));
        $this->assertFalse($pending->can('delete', $account));
    }
}

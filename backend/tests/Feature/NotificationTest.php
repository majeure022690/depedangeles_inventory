<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentCondition;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Personnel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->office = Office::factory()->create(['school_id' => 107001]);
        $this->officer = Personnel::factory()->create(['office_id' => $this->office->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): Equipment
    {
        return Equipment::factory()->create([
            'item_type_id' => ItemType::where('name', 'Laptop')->valueOrFail('id'),
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->valueOrFail('id'),
            'non_functional' => false,
            'disposition_status' => 'Normal',
            'current_accountable_officer_id' => $this->officer->id,
            'current_end_user_id' => null,
            'used_by' => 'Teacher',
            'school_level' => 'elementary',
            'acquisition_cost' => 30000,
            'end_warranty_date' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<Permission>  $permissions
     * @return array<string, array{count: int, to: string}>
     */
    private function bell(array $permissions, DataScope $scope = DataScope::All, ?int $officeId = null): array
    {
        $user = $this->userWithPermissions($permissions, ['office_id' => $officeId], $scope);
        $items = $this->actingAs($user)->getJson('/api/notifications')->assertOk()->json('data');

        return collect($items)->keyBy('key')->map(fn (array $item) => ['count' => $item['count'], 'to' => $item['to']])->all();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_equipment_to_dos_count_only_for_holders_of_the_permission(): void
    {
        $this->item(['awaiting_receipt_since' => now()]);
        $this->item(['used_by' => null]);
        $this->item(['school_level' => null]);
        $this->item(['property_no' => null]);
        $this->item(['end_warranty_date' => now()->addDays(10)->toDateString()]);

        $editor = $this->bell([Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentTransactionsCreate]);

        $this->assertSame(['count' => 1, 'to' => '/equipment?awaiting_receipt=1'], $editor['awaiting_receipt']);
        $this->assertSame(1, $editor['gsp_gaps']['count']);
        $this->assertSame(1, $editor['no_school_level']['count']);
        $this->assertSame(1, $editor['pending_property_no']['count']);
        $this->assertSame(1, $editor['warranty_ending']['count']);

        $viewer = $this->bell([Permission::EquipmentView]);
        $this->assertSame(['warranty_ending'], array_keys(array_intersect_key($viewer, array_flip(['awaiting_receipt', 'gsp_gaps', 'no_school_level', 'pending_property_no', 'warranty_ending']))));
    }

    public function test_counts_stay_within_the_users_scope(): void
    {
        $other = Office::factory()->create();
        $this->item(['school_level' => null, 'current_accountable_officer_id' => Personnel::factory()->create(['office_id' => $other->id])->id]);
        $this->item(['school_level' => null]);

        $this->assertSame(2, $this->bell([Permission::EquipmentView, Permission::EquipmentEdit])['no_school_level']['count']);
        $this->assertSame(1, $this->bell([Permission::EquipmentView, Permission::EquipmentEdit], DataScope::School, $this->office->id)['no_school_level']['count']);
    }

    public function test_office_records_point_a_scoped_user_at_their_own_office(): void
    {
        $bell = $this->bell([Permission::StakeholderProfileEdit, Permission::InternetConnectivityEdit, Permission::GspProfilesCreate], DataScope::School, $this->office->id);

        $this->assertSame(['count' => 1, 'to' => "/stakeholder-profiles/{$this->office->id}"], $bell['stakeholder_profile_incomplete']);
        $this->assertSame(['count' => 1, 'to' => "/internet-connectivity-surveys/{$this->office->id}"], $bell['connectivity_not_started']);
        $this->assertSame(['count' => 1, 'to' => '/gsp-profiles/new'], $bell['gsp_profile_missing']);
    }

    public function test_admin_items_need_their_own_permissions(): void
    {
        User::factory()->create(['office_id' => null, 'is_active' => true]);
        activity()->event('auth.login_failed')->withProperties(['email' => 'someone@example.com'])->log('auth.login_failed');

        $admin = $this->bell([Permission::UsersEdit, Permission::SecuritySettingsManage]);
        $this->assertSame('/users?office_id=none', $admin['users_without_office']['to']);
        $this->assertSame(['count' => 1, 'to' => '/audit-log?event=auth.login_failed'], $admin['failed_sign_ins']);

        $plain = $this->bell([Permission::EquipmentView]);
        $this->assertArrayNotHasKey('users_without_office', $plain);
        $this->assertArrayNotHasKey('failed_sign_ins', $plain);
    }

    public function test_a_failed_password_sign_in_is_audited(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $this->assertTrue(Activity::query()->where('event', 'auth.login_failed')->exists());
    }

    public function test_equipment_list_filters_behind_the_links(): void
    {
        $gap = $this->item(['used_by' => null]);
        $pending = $this->item(['property_no' => null]);
        $warranty = $this->item(['end_warranty_date' => now()->addDays(5)->toDateString()]);
        $this->item();
        $user = $this->userWithPermissions([Permission::EquipmentView]);

        foreach (['gsp_gaps=1' => $gap, 'pending=1' => $pending, 'warranty_ending=1' => $warranty] as $query => $expected) {
            $this->actingAs($user)->getJson("/api/equipment?{$query}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $expected->id);
        }
    }
}

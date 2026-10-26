<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\IspSubscriptionCost;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class IspAccountDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const WRITE = [Permission::IspAccountsView, Permission::IspAccountsCreate, Permission::IspAccountsEdit, Permission::IspAccountsDelete];

    private Office $office;

    private Office $otherOffice;

    private IspAccount $ours;

    private IspAccount $theirs;

    private IspAccount $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $this->otherOffice = Office::factory()->create();
        $this->ours = $this->account(['office_id' => $this->office->id, 'cost_per_month' => 1000]);
        $this->theirs = $this->account(['office_id' => $this->otherOffice->id, 'cost_per_month' => 2000]);
        $this->unassigned = $this->account(['office_id' => null, 'cost_per_month' => 4000]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): IspAccount
    {
        return IspAccount::factory()->create(array_merge([
            'isp_provider_id' => IspProvider::where('name', 'PLDT')->value('id'),
            'school_area_coverage' => 'ES',
            'subscription_type' => 'Postpaid',
            'isp_connection_type' => 'Fiber',
            'purpose_of_subscription' => 'For administrative use',
            'mode_of_acquisition' => 'DepEd Purchase',
            'source_of_acquisition' => 'Central Office',
            'fund_source' => 'Maintenance and Other Operating Expenses (MOOE)',
            'overall_signal_quality' => 'Strong',
            'inactive_contract' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'isp_provider_id' => IspProvider::where('name', 'PLDT')->value('id'),
            'cost_per_month' => 5000,
            'isp_billing_account_no' => 'BA-'.uniqid(),
            'package_purchased_inclusion' => 'Fiber 50Mbps plan',
            'school_area_coverage' => 'ES',
            'min_speed' => 25,
            'max_speed' => 50,
            'subscription_type' => 'Postpaid',
            'isp_connection_type' => 'Fiber',
            'purpose_of_subscription' => 'For administrative use',
            'mode_of_acquisition' => 'DepEd Purchase',
            'source_of_acquisition' => 'Central Office',
            'fund_source' => 'Maintenance and Other Operating Expenses (MOOE)',
            'overall_signal_quality' => 'Strong',
        ], $overrides);
    }

    private function scoped(DataScope $scope, bool $withOffice = true): User
    {
        return $this->userWithPermissions(self::WRITE, ['office_id' => $withOffice ? $this->office->id : null], $scope);
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/isp-accounts')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_own_and_school_scope_list_only_the_users_office(): void
    {
        $this->assertSame([$this->ours->id], $this->listedIds($this->scoped(DataScope::Own)));
        $this->assertSame([$this->ours->id], $this->listedIds($this->scoped(DataScope::School)));
    }

    public function test_scoped_user_without_an_office_sees_none(): void
    {
        $this->assertSame([], $this->listedIds($this->scoped(DataScope::School, withOffice: false)));
    }

    public function test_all_scope_lists_every_account_including_unassigned(): void
    {
        $this->assertCount(3, $this->listedIds($this->scoped(DataScope::All, withOffice: false)));
    }

    public function test_index_exposes_the_office(): void
    {
        $this->actingAs($this->scoped(DataScope::School))->getJson('/api/isp-accounts')
            ->assertJsonPath('data.0.office_id', $this->office->id)
            ->assertJsonPath('data.0.office.id', $this->office->id);
    }

    public function test_out_of_reach_reads_and_writes_are_404_and_change_nothing(): void
    {
        $user = $this->scoped(DataScope::School);

        foreach ([$this->theirs, $this->unassigned] as $account) {
            $this->actingAs($user)->getJson("/api/isp-accounts/{$account->id}")->assertNotFound();
            $this->actingAs($user)->putJson("/api/isp-accounts/{$account->id}", $this->payload())->assertNotFound();
            $this->actingAs($user)->deleteJson("/api/isp-accounts/{$account->id}")->assertNotFound();
            $this->actingAs($user)->postJson("/api/isp-accounts/{$account->id}/speed-tests", [
                'download_speed' => 40, 'upload_speed' => 10, 'tested_at' => now()->toDateTimeString(), 'signal_quality' => 'Strong',
            ])->assertNotFound();
            $this->actingAs($user)->postJson("/api/isp-accounts/{$account->id}/subscription-costs", [
                'contract_start_date' => '2026-01-01', 'contract_end_date' => '2026-12-31', 'total_amount_spent' => 60000,
            ])->assertNotFound();
            $this->assertNotSoftDeleted($account);
        }

        $this->assertSame(0, IspSpeedTest::count());
        $this->assertSame(0, IspSubscriptionCost::count());
        $this->assertSame(2000.0, (float) $this->theirs->fresh()->cost_per_month);
    }

    public function test_in_reach_writes_succeed(): void
    {
        $user = $this->scoped(DataScope::School);

        $this->actingAs($user)->getJson("/api/isp-accounts/{$this->ours->id}")->assertOk();
        $this->actingAs($user)->postJson("/api/isp-accounts/{$this->ours->id}/speed-tests", [
            'download_speed' => 40, 'upload_speed' => 10, 'tested_at' => now()->toDateTimeString(), 'signal_quality' => 'Strong',
        ])->assertCreated();
        $this->actingAs($user)->postJson("/api/isp-accounts/{$this->ours->id}/subscription-costs", [
            'contract_start_date' => '2026-01-01', 'contract_end_date' => '2026-12-31', 'total_amount_spent' => 60000,
        ])->assertCreated();
        $this->actingAs($user)->putJson("/api/isp-accounts/{$this->ours->id}", $this->payload(['cost_per_month' => 1500]))->assertOk();
        $this->actingAs($user)->deleteJson("/api/isp-accounts/{$this->ours->id}")->assertNoContent();
    }

    public function test_missing_permission_stays_403_for_out_of_reach_accounts(): void
    {
        $viewer = $this->userWithPermissions([Permission::IspAccountsView], ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($viewer)->putJson("/api/isp-accounts/{$this->theirs->id}", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/isp-accounts/{$this->theirs->id}/speed-tests", [])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/isp-accounts/{$this->theirs->id}")->assertForbidden();
    }

    public function test_scoped_store_is_forced_into_the_creators_office(): void
    {
        $user = $this->scoped(DataScope::Own);

        $this->actingAs($user)->postJson('/api/isp-accounts', $this->payload())
            ->assertCreated()->assertJsonPath('data.office_id', $this->office->id);
        $this->actingAs($user)->postJson('/api/isp-accounts', $this->payload(['office_id' => $this->office->id]))
            ->assertCreated()->assertJsonPath('data.office_id', $this->office->id);

        $this->actingAs($user)->postJson('/api/isp-accounts', $this->payload(['isp_billing_account_no' => 'BA-X', 'office_id' => $this->otherOffice->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');
        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-X']);
    }

    public function test_scoped_store_without_an_office_is_rejected(): void
    {
        $this->actingAs($this->scoped(DataScope::School, withOffice: false))
            ->postJson('/api/isp-accounts', $this->payload(['isp_billing_account_no' => 'BA-NO']))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');

        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-NO']);
    }

    public function test_scoped_update_cannot_move_the_account_to_another_office(): void
    {
        $user = $this->scoped(DataScope::School);

        $this->actingAs($user)->putJson("/api/isp-accounts/{$this->ours->id}", $this->payload(['office_id' => $this->otherOffice->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');
        $this->actingAs($user)->putJson("/api/isp-accounts/{$this->ours->id}", $this->payload(['office_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');

        $this->assertSame($this->office->id, $this->ours->fresh()->office_id);

        $this->actingAs($user)->putJson("/api/isp-accounts/{$this->ours->id}", $this->payload(['office_id' => $this->office->id]))->assertOk();
    }

    public function test_all_scope_can_assign_any_active_office(): void
    {
        $admin = $this->scoped(DataScope::All, withOffice: false);
        $closed = Office::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->postJson('/api/isp-accounts', $this->payload(['office_id' => $this->otherOffice->id]))
            ->assertCreated()->assertJsonPath('data.office_id', $this->otherOffice->id);
        $this->actingAs($admin)->postJson('/api/isp-accounts', $this->payload())
            ->assertCreated()->assertJsonPath('data.office_id', null);
        $this->actingAs($admin)->postJson('/api/isp-accounts', $this->payload(['office_id' => $closed->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('office_id');

        $this->actingAs($admin)->putJson("/api/isp-accounts/{$this->unassigned->id}", $this->payload(['office_id' => $this->office->id]))
            ->assertOk()->assertJsonPath('data.office_id', $this->office->id);

        $this->theirs->update(['office_id' => $closed->id]);
        $this->actingAs($admin)->putJson("/api/isp-accounts/{$this->theirs->id}", $this->payload(['office_id' => $closed->id]))->assertOk();
    }

    public function test_options_offer_active_offices(): void
    {
        Office::factory()->create(['is_active' => false]);

        $values = collect($this->actingAs($this->scoped(DataScope::All))->getJson('/api/isp-accounts/options')->assertOk()->json('data.form.office'))
            ->pluck('value')->sort()->values()->all();

        $this->assertSame([$this->office->id, $this->otherOffice->id], $values);
    }
}

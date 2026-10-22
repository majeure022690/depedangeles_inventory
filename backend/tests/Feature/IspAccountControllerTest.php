<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Enums\Permission;
use App\Models\Brand;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSpeedTest;
use App\Models\IspSubscriptionCost;
use App\Models\ItemType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * `isp_provider_id` is a real FK (Tier 1); the remaining lookup-backed
 * fields are validated strings against ConnectivityLibrary, or against
 * EquipmentLibrary for the acquisition/fund fields shared with Equipment.
 * Speed tests and subscription costs are append-only child logs
 * authorized against `update` on the parent account.
 */
class IspAccountControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function encoder(): User
    {
        return $this->userWithRole('encoder');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'isp_provider_id' => IspProvider::where('name', 'PLDT')->value('id'),
            'cost_per_month' => 5000,
            'isp_billing_account_no' => 'BA-0001',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIspAccount(array $overrides = []): IspAccount
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
        ], $overrides));
    }

    public function test_store_validates_required_fields_and_creates_record(): void
    {
        $user = $this->encoder();

        $this->actingAs($user)->postJson('/api/isp-accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['isp_provider_id', 'cost_per_month', 'isp_billing_account_no', 'school_area_coverage']);

        $response = $this->actingAs($user)->postJson('/api/isp-accounts', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.isp', 'PLDT')
            ->assertJsonPath('data.isp_billing_account_no', 'BA-0001');

        $ispAccount = IspAccount::findOrFail($response->json('data.id'));
        $this->assertSame(IspProvider::where('name', 'PLDT')->value('id'), $ispAccount->isp_provider_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => IspAccount::class,
            'subject_id' => $ispAccount->id,
            'event' => 'created',
            'description' => 'isp_account.created',
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_rejects_a_nonexistent_isp_provider_id(): void
    {
        $this->actingAs($this->encoder())
            ->postJson('/api/isp-accounts', $this->validPayload(['isp_provider_id' => 999999]))
            ->assertJsonValidationErrors(['isp_provider_id']);
    }

    public function test_store_rejects_an_isp_provider_id_that_has_been_deactivated(): void
    {
        $ispProvider = IspProvider::where('name', 'PLDT')->firstOrFail();
        $ispProvider->update(['is_active' => false]);

        $this->actingAs($this->encoder())
            ->postJson('/api/isp-accounts', $this->validPayload(['isp_provider_id' => $ispProvider->id]))
            ->assertJsonValidationErrors(['isp_provider_id']);

        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-0001']);
    }

    public function test_update_rejects_an_isp_provider_id_that_has_been_deactivated(): void
    {
        $globeId = IspProvider::where('name', 'GLOBE')->value('id');
        $ispAccount = $this->makeIspAccount(['isp_provider_id' => $globeId]);

        $ispProvider = IspProvider::where('name', 'PLDT')->firstOrFail();
        $ispProvider->update(['is_active' => false]);

        $this->actingAs($this->encoder())->putJson("/api/isp-accounts/{$ispAccount->id}", $this->validPayload([
            'isp_provider_id' => $ispProvider->id,
            'isp_billing_account_no' => $ispAccount->isp_billing_account_no,
        ]))->assertJsonValidationErrors(['isp_provider_id']);

        $this->assertSame($globeId, $ispAccount->fresh()->isp_provider_id);
    }

    /**
     * equipment_libraries is shared: deactivating one row shrinks the
     * valid set for Equipment and IspAccount at once.
     */
    public function test_deactivating_a_shared_equipment_library_row_is_rejected_by_both_equipment_and_isp_account_validation(): void
    {
        $user = $this->encoder();

        EquipmentLibrary::where('type', EquipmentLibraryType::ModeOfAcquisition)
            ->where('value', 'DepEd Purchase')
            ->firstOrFail()
            ->update(['is_active' => false]);

        $this->assertNotContains('DepEd Purchase', EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition));

        $this->actingAs($user)
            ->postJson('/api/isp-accounts', $this->validPayload(['mode_of_acquisition' => 'DepEd Purchase']))
            ->assertJsonValidationErrors(['mode_of_acquisition']);

        $this->actingAs($user)->postJson('/api/equipment', [
            'property_no' => 'AC-2026-IT-LT-SHARED',
            'item_type_id' => ItemType::where('name', 'Laptop')->value('id'),
            'uom' => 'Piece',
            'brand_id' => Brand::where('name', 'Dell')->value('id'),
            'equipment_category_id' => EquipmentCategory::where('name', 'High-value')->value('id'),
            'equipment_classification_id' => EquipmentClassification::query()->active()->value('id'),
            'acquisition_cost' => 55000,
            'mode_acquisition' => 'DepEd Purchase',
            'source_acquisition' => 'Central Office',
            'source_fund' => 'Maintenance and Other Operating Expenses (MOOE)',
            'allotment_class' => 'Maintenance and Other Operating Expenses (MOOE)',
            'equipment_condition_id' => EquipmentCondition::where('name', 'Serviceable')->value('id'),
            'disposition_status' => 'Normal',
        ])->assertJsonValidationErrors(['mode_acquisition']);

        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-0001']);
        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-SHARED']);
    }

    public function test_max_speed_must_be_greater_than_or_equal_to_min_speed(): void
    {
        $this->actingAs($this->encoder())
            ->postJson('/api/isp-accounts', $this->validPayload(['min_speed' => 50, 'max_speed' => 10]))
            ->assertJsonValidationErrors(['max_speed']);
    }

    public function test_update_persists_changes_and_is_audited(): void
    {
        $ispAccount = $this->makeIspAccount(['cost_per_month' => 5000]);

        $response = $this->actingAs($this->encoder())->putJson("/api/isp-accounts/{$ispAccount->id}", $this->validPayload([
            'cost_per_month' => 7500,
            'isp_billing_account_no' => $ispAccount->isp_billing_account_no,
        ]))->assertOk();

        $this->assertEquals(7500, $response->json('data.cost_per_month'));
        $this->assertEquals(7500, $ispAccount->fresh()->cost_per_month);

        $activity = Activity::query()->where('subject_id', $ispAccount->id)->where('event', 'updated')->sole();
        $this->assertSame('isp_account.updated', $activity->description);
        $this->assertArrayHasKey('cost_per_month', $activity->attribute_changes['attributes']);
    }

    public function test_destroy_soft_deletes_the_record_and_is_audited(): void
    {
        $ispAccount = $this->makeIspAccount();

        $this->actingAs($this->userWithPermissions([Permission::IspAccountsView, Permission::IspAccountsDelete]))->deleteJson("/api/isp-accounts/{$ispAccount->id}")->assertNoContent();

        $this->assertSoftDeleted($ispAccount);
        $this->assertDatabaseHas('activity_log', ['description' => 'isp_account.deleted', 'subject_id' => $ispAccount->id]);
    }

    public function test_viewer_has_no_isp_account_access(): void
    {
        $viewer = $this->userWithRole('viewer');
        $ispAccount = $this->makeIspAccount();

        $this->actingAs($viewer)->getJson('/api/isp-accounts')->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/isp-accounts/{$ispAccount->id}")->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/isp-accounts', $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/isp-accounts/{$ispAccount->id}", $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/isp-accounts/{$ispAccount->id}")->assertForbidden();

        $this->assertNotSoftDeleted('isp_accounts', ['id' => $ispAccount->id]);
    }

    public function test_index_lists_accounts_and_supports_search_and_filters(): void
    {
        $pldtId = IspProvider::where('name', 'PLDT')->value('id');
        $globeId = IspProvider::where('name', 'GLOBE')->value('id');

        $this->makeIspAccount(['isp_provider_id' => $pldtId, 'isp_billing_account_no' => 'BA-AAA', 'inactive_contract' => false]);
        $this->makeIspAccount(['isp_provider_id' => $globeId, 'isp_billing_account_no' => 'BA-BBB', 'inactive_contract' => true]);

        $user = $this->userWithRole('encoder');

        $this->actingAs($user)->getJson('/api/isp-accounts?search=AAA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.isp_billing_account_no', 'BA-AAA')
            ->assertJsonPath('data.0.isp', 'PLDT')
            ->assertJsonMissingPath('data.0.speed_tests');

        // Search also matches the provider's name.
        $this->actingAs($user)->getJson('/api/isp-accounts?search=GLOBE')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.isp_billing_account_no', 'BA-BBB');

        $this->actingAs($user)->getJson("/api/isp-accounts?isp={$globeId}")->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/isp-accounts?status=inactive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.isp_billing_account_no', 'BA-BBB');
        $this->actingAs($user)->getJson('/api/isp-accounts?status=active')->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->getJson('/api/isp-accounts')->assertJsonPath('meta.total', 2);
    }

    public function test_options_ship_form_filter_and_speed_test_lists(): void
    {
        $response = $this->actingAs($this->userWithRole('encoder'))->getJson('/api/isp-accounts/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'form' => ['isp_provider', 'school_level_coverage', 'subscription_type', 'isp_connection_type',
                    'purpose_of_subscription', 'signal_quality', 'mode_of_acquisition', 'source_of_acquisition', 'source_of_funds'],
                'filters' => ['isp_provider'],
                'speed_test' => ['signal_quality'],
            ]]);

        $this->assertIsInt($response->json('data.form.isp_provider.0.value'));
        $this->assertIsString($response->json('data.speed_test.signal_quality.0.value'));
    }

    public function test_speed_test_store_appends_to_history_and_authorizes_against_parent_account(): void
    {
        $encoder = $this->encoder();
        $ispAccount = $this->makeIspAccount();
        $payload = [
            'download_speed' => 40,
            'upload_speed' => 10,
            'tested_at' => now()->toDateTimeString(),
            'signal_quality' => 'Strong',
        ];

        $this->actingAs($this->userWithRole('viewer'))
            ->postJson("/api/isp-accounts/{$ispAccount->id}/speed-tests", $payload)->assertForbidden();

        $this->actingAs($encoder)->postJson("/api/isp-accounts/{$ispAccount->id}/speed-tests", $payload)
            ->assertCreated()
            ->assertJsonPath('data.signal_quality', 'Strong')
            ->assertJsonPath('data.isp_account_id', $ispAccount->id);

        $speedTest = IspSpeedTest::where('isp_account_id', $ispAccount->id)->sole();
        $this->assertSame($encoder->id, $speedTest->recorded_by_user_id);

        $activity = Activity::query()->where('event', 'isp_account.speed_test.recorded')->sole();
        $this->assertSame(IspAccount::class, $activity->subject_type);
        $this->assertSame($ispAccount->id, $activity->subject_id);
        $this->assertSame($speedTest->id, $activity->properties['speed_test_id']);
    }

    public function test_speed_test_tested_at_in_utc_is_stored_as_manila_wall_clock_and_round_trips(): void
    {
        $ispAccount = $this->makeIspAccount();
        $payload = ['download_speed' => 40, 'upload_speed' => 10, 'signal_quality' => 'Strong'];

        $this->actingAs($this->encoder())
            ->postJson("/api/isp-accounts/{$ispAccount->id}/speed-tests", $payload + ['tested_at' => '2026-10-19T02:30:00.000Z'])
            ->assertCreated()
            ->assertJsonPath('data.tested_at', '2026-10-19T02:30:00.000000Z');

        $speedTest = IspSpeedTest::where('isp_account_id', $ispAccount->id)->sole();
        $this->assertSame('2026-10-19 10:30:00', $speedTest->getRawOriginal('tested_at'));
        $this->assertSame('Asia/Manila', $speedTest->tested_at->timezoneName);
        $this->assertTrue($speedTest->tested_at->equalTo(CarbonImmutable::parse('2026-10-19T02:30:00Z')));

        $this->actingAs($this->userWithRole('encoder'))->getJson("/api/isp-accounts/{$ispAccount->id}")
            ->assertOk()
            ->assertJsonPath('data.speed_tests.0.tested_at', '2026-10-19T02:30:00.000000Z');

        // An offset-less value is already app-timezone wall-clock time and is stored as-is.
        $this->actingAs($this->encoder())
            ->postJson("/api/isp-accounts/{$ispAccount->id}/speed-tests", $payload + ['tested_at' => '2026-10-20 08:15:00'])
            ->assertCreated()
            ->assertJsonPath('data.tested_at', '2026-10-20T00:15:00.000000Z');
        $this->assertSame('2026-10-20 08:15:00', IspSpeedTest::latest('id')->first()?->getRawOriginal('tested_at'));

        $this->actingAs($this->encoder())
            ->postJson("/api/isp-accounts/{$ispAccount->id}/speed-tests", $payload + ['tested_at' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tested_at');
    }

    public function test_subscription_cost_store_appends_to_history(): void
    {
        $user = $this->encoder();
        $ispAccount = $this->makeIspAccount();

        $this->actingAs($user)->postJson("/api/isp-accounts/{$ispAccount->id}/subscription-costs", [
            'contract_start_date' => '2026-01-01',
            'contract_end_date' => '2026-12-31',
            'total_amount_spent' => 60000,
        ])->assertCreated()
            ->assertJsonPath('data.contract_start_date', '2026-01-01');

        $subscriptionCost = IspSubscriptionCost::where('isp_account_id', $ispAccount->id)->sole();
        $this->assertSame($user->id, $subscriptionCost->recorded_by_user_id);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'isp_account.subscription_cost.recorded',
            'subject_type' => IspAccount::class,
            'subject_id' => $ispAccount->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_child_logs_are_append_only(): void
    {
        $ispAccount = $this->makeIspAccount();
        $speedTest = IspSpeedTest::factory()->create(['isp_account_id' => $ispAccount->id]);
        $cost = IspSubscriptionCost::factory()->create(['isp_account_id' => $ispAccount->id]);
        $admin = $this->userWithPermissions([Permission::IspAccountsView, Permission::IspAccountsEdit, Permission::IspAccountsDelete]);

        $this->actingAs($admin)->putJson("/api/isp-accounts/{$ispAccount->id}/speed-tests/{$speedTest->id}", [])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/isp-accounts/{$ispAccount->id}/speed-tests/{$speedTest->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/isp-accounts/{$ispAccount->id}/subscription-costs/{$cost->id}", [])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/isp-accounts/{$ispAccount->id}/subscription-costs/{$cost->id}")->assertNotFound();
    }

    public function test_show_includes_speed_test_and_subscription_cost_history(): void
    {
        $ispAccount = $this->makeIspAccount();
        IspSpeedTest::factory()->count(2)->create(['isp_account_id' => $ispAccount->id]);
        IspSubscriptionCost::factory()->count(2)->create(['isp_account_id' => $ispAccount->id]);

        $this->actingAs($this->userWithRole('encoder'))->getJson("/api/isp-accounts/{$ispAccount->id}")
            ->assertOk()
            ->assertJsonPath('data.isp', 'PLDT')
            ->assertJsonPath('data.isp_provider_id', $ispAccount->isp_provider_id)
            ->assertJsonCount(2, 'data.speed_tests')
            ->assertJsonCount(2, 'data.subscription_costs');
    }
}

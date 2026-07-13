<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
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
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * IspAccount's Tier 1 field (isp_provider_id) is a real foreign key as of
 * the lookup-normalization ADR's Step 2+3 — fixtures here pull a real id
 * from isp_providers (seeded by ReferenceDataSeeder) rather than the old
 * Lookup/LookupType system, which IspAccount no longer consults for its
 * own fields. Tier 2 fields (school_area_coverage, subscription_type,
 * isp_connection_type, purpose_of_subscription, overall_signal_quality,
 * rate_connectivity_admin_areas, rate_connectivity_classroom) stay
 * string-validated, now against the real seeded ConnectivityLibrary rows;
 * mode_of_acquisition/source_of_acquisition/fund_source stay
 * string-validated against the real seeded EquipmentLibrary rows (reused
 * from Equipment, per the ADR).
 *
 * The nested IspSpeedTest child resource's `signal_quality` field is cut
 * over too — it validates against ConnectivityLibrary/
 * ConnectivityLibraryType::SignalQuality, the same table/type
 * overall_signal_quality above uses, both seeded by ReferenceDataSeeder.
 * database/factories/IspSpeedTestFactory.php pulls its value from the same
 * source, so no old-system fixture trait is needed anywhere in this file.
 */
class IspAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * isp-accounts/Index.vue and isp-accounts/Edit.vue don't exist yet
     * (Frontend builds them against the prop contract this controller
     * establishes) — same situation UserControllerTest already solved.
     * Sending X-Inertia makes the response pure JSON straight from the
     * controller, never touching the root Blade view's `@vite(...)` call,
     * which is the only place a missing compiled Vue chunk would blow up.
     */
    private function inertiaGet(string $url): TestResponse
    {
        $manifest = public_path('build/manifest.json');
        $version = file_exists($manifest) ? hash_file('xxh128', $manifest) : null;

        return $this->get($url, array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]));
    }

    private function assertInertiaJson(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode($response->getContent(), true);
    }

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
     * Tier 1/Tier 2 fixtures pulled from the real seeded reference tables
     * — mirrors EquipmentControllerTest::makeEquipment()'s override
     * pattern. Callers must ensure ReferenceDataSeeder has run first.
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
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->post(route('isp-accounts.store'), [])
            ->assertSessionHasErrors(['isp_provider_id', 'cost_per_month', 'isp_billing_account_no', 'school_area_coverage']);

        $response = $this->actingAs($user)->post(route('isp-accounts.store'), $this->validPayload());

        $ispAccount = IspAccount::where('isp_billing_account_no', 'BA-0001')->firstOrFail();
        $response->assertRedirect(route('isp-accounts.edit', $ispAccount));
        $this->assertSame(IspProvider::where('name', 'PLDT')->value('id'), $ispAccount->isp_provider_id);
        $this->assertSame('PLDT', $ispAccount->ispProvider->name);
    }

    public function test_store_rejects_a_nonexistent_isp_provider_id(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->post(route('isp-accounts.store'), $this->validPayload(['isp_provider_id' => 999999]))
            ->assertSessionHasErrors(['isp_provider_id']);
    }

    /**
     * Mirrors EquipmentControllerTest::test_store_rejects_an_item_type_id_that_has_been_deactivated()
     * — same Tier 1 deactivation pattern, applied to IspAccount's own Tier 1
     * foreign key (isp_provider_id -> isp_providers).
     */
    public function test_store_rejects_an_isp_provider_id_that_has_been_deactivated(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $ispProvider = IspProvider::where('name', 'PLDT')->firstOrFail();
        $ispProvider->update(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('isp-accounts.store'), $this->validPayload(['isp_provider_id' => $ispProvider->id]))
            ->assertSessionHasErrors(['isp_provider_id']);

        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-0001']);
    }

    /**
     * Same deactivation guard, exercised on the update path — the Tier 1
     * FK rule is identical between IspAccountStoreRequest and
     * IspAccountUpdateRequest, but only the store side had a regression
     * test before this addition.
     */
    public function test_update_rejects_an_isp_provider_id_that_has_been_deactivated(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        // Account starts on GLOBE so the update attempt below (targeting a
        // deactivated PLDT) has a genuinely different id to prove was left
        // untouched by the rejected update.
        $globeId = IspProvider::where('name', 'GLOBE')->value('id');
        $ispAccount = $this->makeIspAccount(['isp_provider_id' => $globeId]);

        $ispProvider = IspProvider::where('name', 'PLDT')->firstOrFail();
        $ispProvider->update(['is_active' => false]);

        $response = $this->actingAs($user)->put(
            route('isp-accounts.update', $ispAccount),
            $this->validPayload([
                'isp_provider_id' => $ispProvider->id,
                'isp_billing_account_no' => $ispAccount->isp_billing_account_no,
            ])
        );

        $response->assertSessionHasErrors(['isp_provider_id']);
        $ispAccount->refresh();
        $this->assertSame($globeId, $ispAccount->isp_provider_id);
    }

    /**
     * The Tier 2 equipment_libraries table is shared: Equipment and
     * IspAccount both validate mode_of_acquisition/source_of_acquisition/
     * fund_source (Equipment: source_fund) against the exact same rows,
     * not duplicated per-resource sets (lookup-normalization ADR). Manually
     * verified during QA; this proves deactivating one shared row shrinks
     * the valid-options set for BOTH resources simultaneously, exercised
     * end-to-end through each resource's own store validation rather than
     * only asserting on the model helper.
     */
    public function test_deactivating_a_shared_equipment_library_row_is_rejected_by_both_equipment_and_isp_account_validation(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $sharedRow = EquipmentLibrary::where('type', EquipmentLibraryType::ModeOfAcquisition)
            ->where('value', 'DepEd Purchase')
            ->firstOrFail();

        $this->assertContains(
            'DepEd Purchase',
            EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition)
        );

        $sharedRow->update(['is_active' => false]);

        $this->assertNotContains(
            'DepEd Purchase',
            EquipmentLibrary::activeValues(EquipmentLibraryType::ModeOfAcquisition)
        );

        // Rejected via IspAccountStoreRequest.
        $this->actingAs($user)
            ->post(route('isp-accounts.store'), $this->validPayload(['mode_of_acquisition' => 'DepEd Purchase']))
            ->assertSessionHasErrors(['mode_of_acquisition']);

        // Rejected via EquipmentStoreRequest — same shared row, different resource.
        $this->actingAs($user)->post(route('equipment.store'), [
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
        ])->assertSessionHasErrors(['mode_acquisition']);

        $this->assertDatabaseMissing('isp_accounts', ['isp_billing_account_no' => 'BA-0001']);
        $this->assertDatabaseMissing('equipment', ['property_no' => 'AC-2026-IT-LT-SHARED']);
    }

    public function test_max_speed_must_be_greater_than_or_equal_to_min_speed(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->post(route('isp-accounts.store'), $this->validPayload(['min_speed' => 50, 'max_speed' => 10]))
            ->assertSessionHasErrors(['max_speed']);
    }

    public function test_update_persists_changes(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $ispAccount = $this->makeIspAccount();

        $response = $this->actingAs($user)->put(
            route('isp-accounts.update', $ispAccount),
            $this->validPayload(['cost_per_month' => 7500, 'isp_billing_account_no' => $ispAccount->isp_billing_account_no])
        );

        $response->assertRedirect(route('isp-accounts.edit', $ispAccount));
        $ispAccount->refresh();
        $this->assertEquals(7500, $ispAccount->cost_per_month);
    }

    public function test_destroy_soft_deletes_the_record(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('division-ict-admin');
        $ispAccount = $this->makeIspAccount();

        $response = $this->actingAs($user)->delete(route('isp-accounts.destroy', $ispAccount));

        $response->assertRedirect(route('isp-accounts.index'));
        $this->assertSoftDeleted($ispAccount);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'isp_account.deleted',
        ]);
    }

    public function test_viewer_cannot_delete(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');
        $ispAccount = $this->makeIspAccount();

        $this->actingAs($user)->delete(route('isp-accounts.destroy', $ispAccount))
            ->assertForbidden();

        $this->assertDatabaseHas('isp_accounts', ['id' => $ispAccount->id, 'deleted_at' => null]);
    }

    public function test_index_lists_accounts_and_supports_search_and_filters(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');

        $pldtId = IspProvider::where('name', 'PLDT')->value('id');
        $globeId = IspProvider::where('name', 'GLOBE')->value('id');

        $this->makeIspAccount(['isp_provider_id' => $pldtId, 'isp_billing_account_no' => 'BA-AAA', 'inactive_contract' => false]);
        $this->makeIspAccount(['isp_provider_id' => $globeId, 'isp_billing_account_no' => 'BA-BBB', 'inactive_contract' => true]);

        $page = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('isp-accounts.index', ['search' => 'AAA']))
        );
        $this->assertSame('isp-accounts/Index', $page['component']);
        $this->assertCount(1, $page['props']['ispAccounts']['data']);
        $this->assertSame('BA-AAA', $page['props']['ispAccounts']['data'][0]['isp_billing_account_no']);
        // Prop reads the related IspProvider's name via the ispProvider()
        // relationship, not the legacy (now-stale on new rows) `isp`
        // string column — the exact "silent breakage" risk the
        // lookup-normalization ADR flags for this seam.
        $this->assertSame('PLDT', $page['props']['ispAccounts']['data'][0]['isp']);

        // Free-text search also matches by provider name (via
        // whereHas('ispProvider', ...) — see IspAccount::scopeSearch()),
        // not just isp_billing_account_no.
        $searchByProviderName = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('isp-accounts.index', ['search' => 'GLOBE']))
        );
        $this->assertCount(1, $searchByProviderName['props']['ispAccounts']['data']);
        $this->assertSame('BA-BBB', $searchByProviderName['props']['ispAccounts']['data'][0]['isp_billing_account_no']);

        $this->assertSame(1, IspAccount::provider($globeId)->count());
        $this->assertSame(1, IspAccount::status('inactive')->count());
        $this->assertSame(1, IspAccount::status('active')->count());
        $this->assertSame(2, IspAccount::status(null)->count());
    }

    public function test_speed_test_store_appends_to_history_and_authorizes_against_parent_account(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $encoder = User::factory()->create();
        $encoder->assignRole('encoder');
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $ispAccount = $this->makeIspAccount();

        $this->actingAs($viewer)->post(route('isp-accounts.speed-tests.store', $ispAccount), [
            'download_speed' => 40,
            'upload_speed' => 10,
            'tested_at' => now()->toDateTimeString(),
            'signal_quality' => 'Strong',
        ])->assertForbidden();

        $response = $this->actingAs($encoder)->post(route('isp-accounts.speed-tests.store', $ispAccount), [
            'download_speed' => 40,
            'upload_speed' => 10,
            'tested_at' => now()->toDateTimeString(),
            'signal_quality' => 'Strong',
        ]);

        $response->assertRedirect(route('isp-accounts.edit', $ispAccount));
        $speedTest = IspSpeedTest::where('isp_account_id', $ispAccount->id)->sole();
        $this->assertSame($encoder->id, $speedTest->recorded_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'isp_account.speed_test.recorded',
            'subject_type' => $ispAccount->getMorphClass(),
            'subject_id' => $ispAccount->id,
        ]);
    }

    public function test_subscription_cost_store_appends_to_history(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $ispAccount = $this->makeIspAccount();

        $response = $this->actingAs($user)->post(route('isp-accounts.subscription-costs.store', $ispAccount), [
            'contract_start_date' => '2026-01-01',
            'contract_end_date' => '2026-12-31',
            'total_amount_spent' => 60000,
        ]);

        $response->assertRedirect(route('isp-accounts.edit', $ispAccount));
        $subscriptionCost = IspSubscriptionCost::where('isp_account_id', $ispAccount->id)->sole();
        $this->assertSame($user->id, $subscriptionCost->recorded_by_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'isp_account.subscription_cost.recorded',
            'subject_type' => $ispAccount->getMorphClass(),
            'subject_id' => $ispAccount->id,
        ]);
    }

    public function test_edit_page_eager_loads_speed_test_and_subscription_cost_history(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');
        $ispAccount = $this->makeIspAccount();
        IspSpeedTest::factory()->count(2)->create(['isp_account_id' => $ispAccount->id]);
        IspSubscriptionCost::factory()->count(2)->create(['isp_account_id' => $ispAccount->id]);

        $page = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('isp-accounts.edit', $ispAccount))
        );

        $this->assertSame('isp-accounts/Edit', $page['component']);
        $this->assertSame('PLDT', $page['props']['ispAccount']['isp']);
        $this->assertSame($ispAccount->isp_provider_id, $page['props']['ispAccount']['isp_provider_id']);
        $this->assertCount(2, $page['props']['ispAccount']['speed_tests']);
        $this->assertCount(2, $page['props']['ispAccount']['subscription_costs']);
    }
}

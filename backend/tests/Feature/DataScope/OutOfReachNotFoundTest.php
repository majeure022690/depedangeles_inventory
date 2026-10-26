<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * A scoped user's request for an existing out-of-reach id must be
 * indistinguishable from a request for an id that doesn't exist, including
 * on routes authorized by a Form Request rather than the controller.
 */
class OutOfReachNotFoundTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const MISSING = 999999;

    private User $user;

    private Office $otherOffice;

    private Personnel $outsider;

    private Equipment $equipment;

    private IspAccount $ispAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $office = Office::factory()->create();
        $this->otherOffice = Office::factory()->create();
        $this->outsider = Personnel::factory()->create(['office_id' => $this->otherOffice->id]);
        $this->equipment = Equipment::factory()->create([
            'current_accountable_officer_id' => $this->outsider->id,
            'current_end_user_id' => null,
        ]);
        $this->ispAccount = IspAccount::factory()->create(['office_id' => $this->otherOffice->id]);

        $this->user = $this->userWithPermissions([
            Permission::PersonnelView, Permission::PersonnelEdit, Permission::PersonnelDelete,
            Permission::EquipmentView, Permission::EquipmentEdit, Permission::EquipmentDelete, Permission::EquipmentTransactionsCreate,
            Permission::IspAccountsView, Permission::IspAccountsEdit, Permission::IspAccountsDelete,
            Permission::StakeholderProfileView, Permission::StakeholderProfileEdit,
            Permission::InternetConnectivityView, Permission::InternetConnectivityEdit,
        ], ['office_id' => $office->id], DataScope::School);
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function requests(): array
    {
        return [
            ['GET', '/api/personnel/{personnel}', []],
            ['PUT', '/api/personnel/{personnel}', ['employee_id' => 'X', 'last_name' => 'X', 'first_name' => 'X']],
            ['DELETE', '/api/personnel/{personnel}', []],
            ['GET', '/api/equipment/{equipment}', []],
            ['GET', '/api/equipment/{equipment}/qr-code', []],
            ['PUT', '/api/equipment/{equipment}', []],
            ['DELETE', '/api/equipment/{equipment}', []],
            ['POST', '/api/equipment/{equipment}/transactions', ['transaction_type' => 'Return']],
            ['GET', '/api/isp-accounts/{isp}', []],
            ['PUT', '/api/isp-accounts/{isp}', []],
            ['DELETE', '/api/isp-accounts/{isp}', []],
            ['POST', '/api/isp-accounts/{isp}/speed-tests', ['download_speed' => 1, 'upload_speed' => 1]],
            ['POST', '/api/isp-accounts/{isp}/subscription-costs', ['total_amount_spent' => 1]],
            ['GET', '/api/stakeholder-profiles/{office}', []],
            ['PUT', '/api/stakeholder-profiles/{office}', ['ro' => 'III']],
            ['GET', '/api/internet-connectivity-surveys/{office}', []],
            ['PUT', '/api/internet-connectivity-surveys/{office}', ['rooms_other_use' => 1]],
        ];
    }

    /**
     * @param  array<string, int>  $ids
     */
    private function uri(string $template, array $ids): string
    {
        return strtr($template, $ids);
    }

    private function assertIndistinguishable(): void
    {
        $existing = [
            '{personnel}' => (string) $this->outsider->id,
            '{equipment}' => (string) $this->equipment->id,
            '{isp}' => (string) $this->ispAccount->id,
            '{office}' => (string) $this->otherOffice->id,
        ];
        $missing = array_map(fn () => (string) self::MISSING, $existing);

        foreach ($this->requests() as [$method, $template, $body]) {
            $outOfReach = $this->actingAs($this->user)->json($method, $this->uri($template, $existing), $body);
            $nonexistent = $this->actingAs($this->user)->json($method, $this->uri($template, $missing), $body);

            $label = "{$method} {$template}";
            $this->assertSame(404, $outOfReach->status(), "{$label} out of reach answered {$outOfReach->status()}");
            $this->assertSame(404, $nonexistent->status(), "{$label} nonexistent answered {$nonexistent->status()}");
            $this->assertSame($nonexistent->json(), $outOfReach->json(), "{$label} body differs");
            $this->assertSame(['message' => 'Not Found.'], $outOfReach->json(), "{$label} is not the generic 404 body");
        }

        $this->assertNotSoftDeleted($this->outsider);
        $this->assertNotSoftDeleted($this->equipment);
        $this->assertNotSoftDeleted($this->ispAccount);
        $this->assertSame(0, $this->equipment->transactions()->count());
        $this->assertSame(0, StakeholderProfile::count());
        $this->assertSame(0, InternetConnectivitySurvey::count());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function maskedEnvironments(): array
    {
        return [
            'production' => ['production', false],
            'staging with debug on' => ['staging', true],
        ];
    }

    #[DataProvider('maskedEnvironments')]
    public function test_out_of_reach_ids_answer_the_same_404_as_missing_ids(string $environment, bool $debug): void
    {
        // Outside unit tests CSRF applies to every stateful write; it isn't what's under test here.
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app['env'] = $environment;
        config(['app.debug' => $debug]);

        $this->assertIndistinguishable();
    }
}

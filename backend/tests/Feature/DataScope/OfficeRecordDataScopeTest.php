<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\InternetConnectivitySurvey;
use App\Models\Office;
use App\Models\StakeholderProfile;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Stakeholder Profile and the Internet Connectivity Survey: one row per office,
 * reached by own/school only at the user's office and by all everywhere.
 */
class OfficeRecordDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private Office $otherOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $this->otherOffice = Office::factory()->create();
    }

    /**
     * @return array<string, array{string, list<Permission>, class-string, array<string, mixed>}>
     */
    public static function resources(): array
    {
        return [
            'stakeholder profile' => [
                '/api/stakeholder-profiles',
                [Permission::StakeholderProfileView, Permission::StakeholderProfileEdit],
                StakeholderProfile::class,
                ['ro' => 'III'],
            ],
            'connectivity survey' => [
                '/api/internet-connectivity-surveys',
                [Permission::InternetConnectivityView, Permission::InternetConnectivityEdit],
                InternetConnectivitySurvey::class,
                ['rooms_other_use' => 2],
            ],
        ];
    }

    /**
     * @param  list<Permission>  $permissions
     * @param  class-string<StakeholderProfile|InternetConnectivitySurvey>  $model
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('resources')]
    public function test_own_and_school_scope_reach_only_the_users_office(string $base, array $permissions, string $model, array $body): void
    {
        foreach ([DataScope::Own, DataScope::School] as $scope) {
            $user = $this->userWithPermissions($permissions, ['office_id' => $this->office->id], $scope);

            $this->actingAs($user)->getJson("{$base}/{$this->office->id}")->assertOk();
            $this->actingAs($user)->putJson("{$base}/{$this->office->id}", $body)->assertOk();
            $this->actingAs($user)->getJson("{$base}/{$this->otherOffice->id}")->assertNotFound();
            $this->actingAs($user)->putJson("{$base}/{$this->otherOffice->id}", $body)->assertNotFound();
            $this->actingAs($user)->getJson($base)->assertForbidden();
            $this->actingAs($user)->getJson("{$base}/options")->assertOk();
        }

        $this->assertSame(1, $model::query()->where('office_id', $this->office->id)->count());
        $this->assertSame(0, $model::query()->where('office_id', $this->otherOffice->id)->count());
    }

    /**
     * @param  list<Permission>  $permissions
     * @param  class-string<StakeholderProfile|InternetConnectivitySurvey>  $model
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('resources')]
    public function test_all_scope_reaches_every_office_and_the_list(string $base, array $permissions, string $model, array $body): void
    {
        $user = $this->userWithPermissions($permissions, ['office_id' => null], DataScope::All);

        $this->actingAs($user)->getJson($base)->assertOk();
        $this->actingAs($user)->getJson("{$base}/{$this->otherOffice->id}")->assertOk();
        $this->actingAs($user)->putJson("{$base}/{$this->otherOffice->id}", $body)->assertOk();

        $this->assertSame(1, $model::query()->where('office_id', $this->otherOffice->id)->count());
    }

    /**
     * @param  list<Permission>  $permissions
     * @param  class-string<StakeholderProfile|InternetConnectivitySurvey>  $model
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('resources')]
    public function test_view_without_edit_is_403_even_at_the_users_office(string $base, array $permissions, string $model, array $body): void
    {
        $user = $this->userWithPermissions([$permissions[0]], ['office_id' => $this->office->id], DataScope::All);

        $this->actingAs($user)->putJson("{$base}/{$this->office->id}", $body)->assertForbidden();
        $this->actingAs($user)->putJson("{$base}/{$this->otherOffice->id}", $body)->assertForbidden();

        $this->assertSame(0, $model::query()->count());
    }
}

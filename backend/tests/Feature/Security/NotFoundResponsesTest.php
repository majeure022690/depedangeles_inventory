<?php

namespace Tests\Feature\Security;

use App\Models\Equipment;
use App\Models\Personnel;
use App\Models\StakeholderProfile;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Unknown or soft-deleted ids answer a generic JSON 404 for a fully
 * permitted user, and office-scoped endpoints never create a row for an
 * office that doesn't exist.
 */
class NotFoundResponsesTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unknown_ids_are_404_on_every_bound_management_route(): void
    {
        $admin = $this->userWithRole('super_administrator');

        $requests = [
            ['GET', '/api/personnel/999999'],
            ['PUT', '/api/personnel/999999'],
            ['DELETE', '/api/personnel/999999'],
            ['GET', '/api/equipment/999999/qr-code'],
            ['POST', '/api/equipment/999999/transactions'],
            ['GET', '/api/isp-accounts/999999'],
            ['POST', '/api/isp-accounts/999999/speed-tests'],
            ['POST', '/api/isp-accounts/999999/subscription-costs'],
            ['GET', '/api/offices/999999'],
            ['PUT', '/api/offices/999999'],
            ['DELETE', '/api/offices/999999'],
            ['GET', '/api/stakeholder-profiles/999999'],
            ['PUT', '/api/stakeholder-profiles/999999'],
            ['GET', '/api/internet-connectivity-surveys/999999'],
            ['PUT', '/api/internet-connectivity-surveys/999999'],
            ['PUT', '/api/users/999999'],
            ['DELETE', '/api/users/999999'],
            ['POST', '/api/users/999999/2fa/reset'],
            ['GET', '/api/roles/999999'],
            ['PUT', '/api/roles/999999'],
            ['DELETE', '/api/roles/999999'],
            ['PATCH', '/api/reference-data/item-types/999999'],
            ['DELETE', '/api/reference-data/item-types/999999'],
        ];

        foreach ($requests as [$method, $uri]) {
            $response = $this->actingAs($admin)->json($method, $uri);

            $this->assertSame(404, $response->status(), "{$method} {$uri} answered {$response->status()}");
            $this->assertStringNotContainsString('App\\Models', (string) $response->getContent(), "{$method} {$uri} leaks a model name");
        }

        $this->assertSame(0, StakeholderProfile::count());
    }

    public function test_soft_deleted_records_are_404(): void
    {
        $admin = $this->userWithRole('super_administrator');

        $person = Personnel::factory()->create();
        $equipment = Equipment::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/personnel/{$person->id}")->assertNoContent();
        $this->actingAs($admin)->deleteJson("/api/equipment/{$equipment->id}")->assertNoContent();

        $this->actingAs($admin)->getJson("/api/personnel/{$person->id}")->assertNotFound();
        $this->actingAs($admin)->getJson("/api/equipment/{$equipment->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/equipment/{$equipment->id}", [])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/equipment/{$equipment->id}/transactions", [])->assertNotFound();
    }
}

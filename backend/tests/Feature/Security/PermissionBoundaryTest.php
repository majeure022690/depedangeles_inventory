<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Guest refusal and denial auditing for management routes. Per-route
 * permission denial is covered by UnrelatedPermissionBoundaryTest.
 */
class PermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_level_denials_are_audited_but_throttled_per_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create()->givePermissionTo(Permission::PersonnelView->value);

        foreach (range(1, 15) as $ignored) {
            $this->actingAs($user)->postJson('/api/offices', [])->assertForbidden()->assertJsonMissingPath('code');
        }

        $this->assertSame(10, Activity::where('event', 'authorization.denied')->where('causer_id', $user->id)->count());

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/api/offices', [])->assertForbidden();
        $this->assertSame(11, Activity::where('event', 'authorization.denied')->where('causer_id', $user->id)->count());
    }

    public function test_every_management_route_rejects_guests(): void
    {
        foreach ($this->managementRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $uri = preg_replace(['/\{table\}/', '/\{(\w+)\??\}/'], ['item-types', '1'], $route->uri());

                $this->json($method, '/'.$uri)->assertUnauthorized();
            }
        }
    }

    /**
     * @return list<RoutingRoute>
     */
    private function managementRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => Str::startsWith($route->getActionName(), 'App\\Http\\Controllers\\Api\\Management\\'))
            ->values()
            ->all();
    }
}

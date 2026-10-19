<?php

namespace Tests\Feature\Security;

use App\Models\Equipment;
use App\Models\IspAccount;
use App\Models\ItemType;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every management route must refuse a zero-permission `pending` user.
 * The route list is read live, so a new endpoint that forgets its
 * permission gate fails here instead of shipping open.
 */
class PermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticated-only by design: the dashboard omits every section
     * instead of 403ing.
     */
    private const OPEN_TO_ANY_USER = ['GET api/dashboard/summary'];

    public function test_every_management_route_is_forbidden_for_a_pending_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $pending = User::factory()->create();
        $pending->assignRole(Role::PENDING);

        $target = User::factory()->create();
        $parameters = [
            'personnel' => Personnel::factory()->create()->id,
            'equipment' => Equipment::factory()->create()->id,
            'ispAccount' => IspAccount::factory()->create()->id,
            'office' => Office::create(['office_name' => 'Boundary ES'])->id,
            'user' => $target->id,
            'role' => Role::where('name', 'viewer')->value('id'),
            'table' => 'item-types',
            'id' => ItemType::create(['name' => 'Boundary', 'sort_order' => 0, 'is_active' => true])->id,
        ];

        $checked = 0;

        foreach ($this->managementRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();

                if (in_array($key, self::OPEN_TO_ANY_USER, true)) {
                    continue;
                }

                $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($parameters, $key) {
                    $this->assertArrayHasKey($match[1], $parameters, "No fixture for {{$match[1]}} in {$key}");

                    return (string) $parameters[$match[1]];
                }, $route->uri());

                $this->actingAs($pending)->json($method, '/'.$uri)
                    ->assertForbidden();

                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
        $this->assertNotNull($target->fresh());
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

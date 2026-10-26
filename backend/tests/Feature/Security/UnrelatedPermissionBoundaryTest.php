<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
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
 * A zero-permission user is refused by EnsureUserIsActive before any
 * route gate or Policy runs, so each management route is exercised with a
 * user holding exactly one permission that route does not list.
 */
class UnrelatedPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_TO_ANY_USER = ['GET api/dashboard/summary'];

    public function test_every_management_route_is_forbidden_for_a_holder_of_an_unrelated_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $target = User::factory()->create();
        $parameters = [
            'personnel' => Personnel::factory()->create()->id,
            'equipment' => Equipment::factory()->create()->id,
            'ispAccount' => IspAccount::factory()->create()->id,
            'office' => Office::create(['office_name' => 'Boundary ES'])->id,
            'user' => $target->id,
            'role' => Role::create(['name' => 'boundary-role', 'label' => 'Boundary Role'])->id,
            'table' => 'item-types',
            'id' => ItemType::create(['name' => 'Boundary', 'sort_order' => 0, 'is_active' => true])->id,
        ];

        $checked = 0;

        foreach ($this->managementRoutes() as $route) {
            $listed = $this->listedPermissions($route);
            $unrelated = collect(Permission::cases())->first(fn (Permission $p) => ! in_array($p->value, $listed, true));
            $this->assertNotNull($unrelated);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();

                if (in_array($key, self::OPEN_TO_ANY_USER, true)) {
                    continue;
                }

                $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($parameters, $key) {
                    $this->assertArrayHasKey($match[1], $parameters, "No fixture for {{$match[1]}} in {$key}");

                    return (string) $parameters[$match[1]];
                }, $route->uri());

                $user = User::factory()->create()->givePermissionTo($unrelated->value);
                $this->app['auth']->forgetGuards();

                $response = $this->actingAs($user)->json($method, '/'.$uri);
                $this->assertSame(403, $response->status(), "{$key} with only {$unrelated->value} returned {$response->status()}");
                $this->assertNull($response->json('code'), "{$key} was refused by the no-access gate, not its own permission check");

                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
        $this->assertNotNull($target->fresh());
    }

    /**
     * @return list<string>
     */
    private function listedPermissions(RoutingRoute $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && Str::startsWith($m, 'permission:'))
            ->flatMap(fn (string $m) => explode('|', Str::after($m, 'permission:')))
            ->values()
            ->all();
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

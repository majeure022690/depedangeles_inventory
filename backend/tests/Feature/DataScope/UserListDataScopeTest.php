<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class UserListDataScopeTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private Office $office;

    private User $colleague;

    private User $stranger;

    private User $officeless;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->office = Office::factory()->create();
        $this->colleague = User::factory()->create(['office_id' => $this->office->id]);
        $this->stranger = User::factory()->create(['office_id' => Office::factory()->create()->id]);
        $this->officeless = User::factory()->create(['office_id' => null]);
    }

    /**
     * @return list<int>
     */
    private function listedIds(User $actor, string $query = ''): array
    {
        return collect($this->actingAs($actor)->getJson('/api/users?per_page=100'.$query)->assertOk()->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_scoped_actor_lists_only_users_in_its_office(): void
    {
        foreach ([DataScope::Own, DataScope::School] as $scope) {
            $actor = $this->userWithPermissions([Permission::UsersView], ['office_id' => $this->office->id], $scope);

            $ids = $this->listedIds($actor);

            $this->assertContains($actor->id, $ids);
            $this->assertContains($this->colleague->id, $ids);
            $this->assertNotContains($this->stranger->id, $ids);
            $this->assertNotContains($this->officeless->id, $ids);
        }
    }

    public function test_office_filter_cannot_reach_another_office(): void
    {
        $actor = $this->userWithPermissions([Permission::UsersView], ['office_id' => $this->office->id], DataScope::School);

        $this->assertSame([], $this->listedIds($actor, '&office_id='.$this->stranger->office_id));
    }

    public function test_scoped_actor_without_an_office_lists_only_itself(): void
    {
        $actor = $this->userWithPermissions([Permission::UsersView], ['office_id' => null], DataScope::School);

        $this->assertSame([$actor->id], $this->listedIds($actor));
    }

    public function test_all_scope_lists_every_user(): void
    {
        $actor = $this->userWithPermissions([Permission::UsersView], ['office_id' => $this->office->id], DataScope::All);

        $ids = $this->listedIds($actor);

        foreach ([$actor, $this->colleague, $this->stranger, $this->officeless] as $user) {
            $this->assertContains($user->id, $ids);
        }
    }

    public function test_listing_requires_users_view(): void
    {
        $actor = $this->userWithPermissions([Permission::PersonnelView], ['office_id' => $this->office->id], DataScope::School);

        $this->actingAs($actor)->getJson('/api/users')->assertForbidden();
    }
}

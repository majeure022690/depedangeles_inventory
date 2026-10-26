<?php

namespace Tests\Feature\DataScope;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        foreach (DataScope::cases() as $scope) {
            Role::create(['name' => 'scope-'.$scope->value, 'label' => 'Scope '.$scope->value, 'data_scope' => $scope])
                ->givePermissionTo(Permission::EquipmentView->value);
        }
    }

    private function withScopes(DataScope ...$scopes): User
    {
        $user = User::factory()->create(['office_id' => Office::factory()->create()->id]);
        $user->assignRole(array_map(fn (DataScope $scope) => 'scope-'.$scope->value, $scopes));

        return $user->fresh();
    }

    public function test_a_user_without_roles_is_own(): void
    {
        $this->assertSame(DataScope::Own, User::factory()->create()->dataScope());
    }

    public function test_a_single_role_resolves_to_its_scope(): void
    {
        foreach (DataScope::cases() as $scope) {
            $this->assertSame($scope, $this->withScopes($scope)->dataScope());
        }
    }

    public function test_the_widest_scope_among_multiple_roles_wins(): void
    {
        $this->assertSame(DataScope::School, $this->withScopes(DataScope::Own, DataScope::School)->dataScope());
        $this->assertSame(DataScope::All, $this->withScopes(DataScope::Own, DataScope::School, DataScope::All)->dataScope());
    }

    public function test_new_roles_default_to_own_at_the_database_level(): void
    {
        $role = Role::create(['name' => 'no-scope-given', 'label' => 'No scope given']);

        $this->assertSame(DataScope::Own, $role->fresh()->data_scope);
    }

    public function test_scoped_personnel_ids(): void
    {
        $own = $this->withScopes(DataScope::Own);
        $school = $this->withScopes(DataScope::School);
        $all = $this->withScopes(DataScope::All);

        $ownRecord = Personnel::factory()->create();
        $schoolRecord = Personnel::factory()->create();
        $own->forceFill(['employee_no' => $ownRecord->employee_id])->save();
        $school->forceFill(['employee_no' => $schoolRecord->employee_id])->save();
        $atSchoolOffice = Personnel::factory()->create(['office_id' => $school->office_id]);
        $atOwnOffice = Personnel::factory()->create(['office_id' => $own->office_id]);
        Personnel::factory()->create(['office_id' => null]);

        $this->assertSame([$ownRecord->id], $own->fresh()->scopedPersonnelIds());
        $this->assertEqualsCanonicalizing([$schoolRecord->id, $atSchoolOffice->id], $school->fresh()->scopedPersonnelIds());
        $this->assertNotContains($atOwnOffice->id, $school->fresh()->scopedPersonnelIds());
        $this->assertNull($all->scopedPersonnelIds());
    }

    public function test_soft_deleted_linked_record_is_not_reachable(): void
    {
        $user = $this->withScopes(DataScope::Own);
        $record = Personnel::factory()->create();
        $user->forceFill(['employee_no' => $record->employee_id])->save();
        $record->delete();

        $this->assertSame([], $user->fresh()->scopedPersonnelIds());
    }

    public function test_reaches_office(): void
    {
        $own = $this->withScopes(DataScope::Own);
        $school = $this->withScopes(DataScope::School);
        $all = $this->withScopes(DataScope::All);
        $noOffice = User::factory()->create(['office_id' => null]);
        $noOffice->assignRole('scope-school');
        $other = Office::factory()->create()->id;

        $this->assertTrue($own->reachesOffice($own->office_id));
        $this->assertTrue($school->reachesOffice($school->office_id));
        $this->assertFalse($school->reachesOffice($other));
        $this->assertFalse($school->reachesOffice(null));
        $this->assertFalse($noOffice->fresh()->reachesOffice(null));
        $this->assertFalse($noOffice->fresh()->reachesOffice($other));
        $this->assertTrue($all->reachesOffice($other));
        $this->assertTrue($all->reachesOffice(null));
    }
}

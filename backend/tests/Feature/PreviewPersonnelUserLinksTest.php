<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreviewPersonnelUserLinksTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $email): int
    {
        return DB::table('personnel')->insertGetId([
            'employee_id' => uniqid('E'), 'last_name' => 'Cruz', 'first_name' => 'Ana',
            'deped_email' => $email, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_lists_unambiguous_pairs_and_ambiguous_emails_without_writing(): void
    {
        $user = User::factory()->create(['email' => 'ana@deped.gov.ph']);
        User::factory()->create(['email' => 'dup@deped.gov.ph']);
        $matched = $this->person(' ANA@deped.gov.ph ');
        $this->person('dup@deped.gov.ph');
        $this->person('Dup@deped.gov.ph');
        $this->person('nobody@deped.gov.ph');

        $before = [DB::table('activity_log')->count(), DB::table('personnel')->whereNotNull('user_id')->count()];

        $this->artisan('personnel:link-preview')
            ->expectsOutputToContain('1 personnel/user pair(s) matched on exactly one email')
            ->expectsTable(
                ['Personnel ID', 'Employee ID', 'Personnel', 'User ID', 'User', 'Email', 'Status'],
                [[$matched, DB::table('personnel')->where('id', $matched)->value('employee_id'), 'Cruz, Ana', $user->id, $user->name, 'ana@deped.gov.ph', 'would link']],
            )
            ->expectsOutputToContain('1 ambiguous email(s) skipped')
            ->assertSuccessful();

        $this->assertSame($before, [DB::table('activity_log')->count(), DB::table('personnel')->whereNotNull('user_id')->count()]);
    }

    public function test_reports_already_linked_pairs(): void
    {
        $user = User::factory()->create(['email' => 'ana@deped.gov.ph']);
        $matched = $this->person('ana@deped.gov.ph');
        DB::table('personnel')->where('id', $matched)->update(['user_id' => $user->id]);

        $this->artisan('personnel:link-preview')
            ->expectsOutputToContain('already linked')
            ->assertSuccessful();
    }

    public function test_lists_office_wide_roles_holding_stakeholder_or_connectivity_permissions(): void
    {
        $permission = fn (string $name) => DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web']);
        $role = fn (string $name, string $scope) => DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web', 'label' => $name, 'data_scope' => $scope]);

        $spView = $permission('stakeholder_profile.view');
        $spViewAll = $permission('stakeholder_profile.view_all');
        $icView = $permission('internet_connectivity.view');

        $wide = $role('wide', 'all');
        $admin = $role('admin', 'all');
        $scoped = $role('scoped', 'school');

        foreach ([[$wide, $spView], [$wide, $icView], [$admin, $spView], [$admin, $spViewAll], [$scoped, $spView]] as [$roleId, $permissionId]) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('model_has_roles')->insert(['role_id' => $wide, 'model_type' => User::class, 'model_id' => User::factory()->create()->id]);

        $this->artisan('personnel:link-preview')
            ->expectsOutputToContain("2 role/domain grant(s) with data_scope 'all'")
            ->expectsTable(['Role ID', 'Role', 'Permissions', 'Users'], [
                [$wide, 'wide', 'stakeholder_profile.view', 1],
                [$wide, 'wide', 'internet_connectivity.view', 1],
            ])
            ->assertSuccessful();
    }
}

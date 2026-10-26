<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreviewPersonnelUserLinks extends Command
{
    protected $signature = 'personnel:link-preview';

    protected $description = 'Read-only: list the user/personnel links the personnel.user_id email backfill would create, the ambiguous emails it would skip, and office-wide roles whose stakeholder/connectivity access widens';

    private const DOMAINS = ['stakeholder_profile', 'internet_connectivity'];

    public function handle(): int
    {
        $this->previewLinks();
        $this->newLine();
        $this->previewWidenedRoles();

        return self::SUCCESS;
    }

    private function previewLinks(): void
    {
        $hasLinkColumn = Schema::hasColumn('personnel', 'user_id');

        $users = DB::table('users')
            ->whereNotNull('email')
            ->get(['id', 'name', 'email'])
            ->groupBy(fn ($user) => mb_strtolower(trim($user->email)));

        $personnel = DB::table('personnel')
            ->whereNull('deleted_at')
            ->whereNotNull('deped_email')
            ->where('deped_email', '!=', '')
            ->get(['id', 'employee_id', 'last_name', 'first_name', 'deped_email', ...($hasLinkColumn ? ['user_id'] : [])])
            ->groupBy(fn ($row) => mb_strtolower(trim($row->deped_email)));

        $pairs = [];
        $ambiguous = [];

        foreach ($personnel as $email => $matches) {
            $userMatches = $users[$email] ?? collect();

            if ($userMatches->isEmpty()) {
                continue;
            }

            if ($matches->count() !== 1 || $userMatches->count() !== 1) {
                $ambiguous[] = [
                    $email,
                    $matches->pluck('id')->implode(', '),
                    $userMatches->pluck('id')->implode(', '),
                ];

                continue;
            }

            $person = $matches->first();
            $user = $userMatches->first();

            $pairs[] = [
                $person->id,
                $person->employee_id,
                "{$person->last_name}, {$person->first_name}",
                $user->id,
                $user->name,
                $email,
                $this->linkStatus($hasLinkColumn && $person->user_id !== null ? (int) $person->user_id : null, (int) $user->id),
            ];
        }

        $this->info(count($pairs).' personnel/user pair(s) matched on exactly one email:');
        if ($pairs !== []) {
            $this->table(['Personnel ID', 'Employee ID', 'Personnel', 'User ID', 'User', 'Email', 'Status'], $pairs);
        }

        $this->newLine();
        $this->info(count($ambiguous).' ambiguous email(s) skipped (more than one personnel or user):');
        if ($ambiguous !== []) {
            $this->table(['Email', 'Personnel IDs', 'User IDs'], $ambiguous);
        }
    }

    private function linkStatus(?int $linkedUserId, int $userId): string
    {
        if ($linkedUserId === null) {
            return 'would link';
        }

        return $linkedUserId === $userId ? 'already linked' : "linked to user {$linkedUserId}";
    }

    private function previewWidenedRoles(): void
    {
        $hasDataScope = Schema::hasColumn('roles', 'data_scope');

        $grants = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where(function ($query): void {
                foreach (self::DOMAINS as $domain) {
                    $query->orWhere('permissions.name', 'like', "{$domain}.%");
                }
            })
            ->when($hasDataScope, fn ($query) => $query->where('roles.data_scope', 'all'))
            ->orderBy('roles.id')
            ->get(['roles.id', 'roles.name', 'permissions.name as permission'])
            ->groupBy('id');

        $userCounts = DB::table('model_has_roles')
            ->whereIn('role_id', $grants->keys())
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as total')
            ->pluck('total', 'role_id');

        $rows = [];

        foreach ($grants as $roleId => $roleGrants) {
            $held = $roleGrants->pluck('permission');

            foreach (self::DOMAINS as $domain) {
                $domainGrants = $held->filter(fn (string $name) => str_starts_with($name, "{$domain}."));

                if ($domainGrants->isEmpty() || $domainGrants->contains("{$domain}.view_all")) {
                    continue;
                }

                $rows[] = [
                    $roleId,
                    $roleGrants->first()->name,
                    $domainGrants->sort()->implode(', '),
                    (int) ($userCounts[$roleId] ?? 0),
                ];
            }
        }

        $this->info(count($rows)." role/domain grant(s) with data_scope 'all' and no view_all, which will see every office's records:");
        if ($rows !== []) {
            $this->table(['Role ID', 'Role', 'Permissions', 'Users'], $rows);
        }
    }
}

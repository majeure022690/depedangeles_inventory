<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\UserRoleUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The users.manage admin screen: a paginated, searchable/filterable list
 * of every user and their current role(s), plus the single action this
 * app needs against them (syncing their role set — a user may hold more
 * than one role, see UserRoleService::syncRoles()) — not a generic
 * user-profile-editing admin page. See UserRoleService for the
 * self-escalation and last-admin-lockout guards enforced on the write
 * side.
 */
class UserController extends Controller
{
    public function index(Request $request, UserRoleService $userRoleService): Response
    {
        $this->authorize(Permission::UsersManage->value);

        $search = $request->string('search')->toString() ?: null;
        $roleFilter = $request->string('role')->toString() ?: null;

        $users = User::query()
            ->with('roles:id,name,label')
            ->when($search, fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"),
            ))
            ->when($roleFilter, fn ($query) => $query->whereHas(
                'roles', fn ($q) => $q->where('name', $roleFilter),
            ))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->label,
                ])->all(),
                'role_ids' => $user->roles->pluck('id')->all(),
                'is_self' => $user->is($request->user()),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            // Only roles the ACTING user could actually assign (their
            // permission set is a subset of the actor's own) — see
            // UserRoleService::assignableRolesFor()'s doc-comment. Keeps
            // the UI from ever offering an option
            // guardAgainstPermissionTierViolation() would reject
            // (CRITICAL, 2026-07 follow-up security review).
            'roles' => $userRoleService->assignableRolesFor($request->user())
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->label,
                ])
                ->values()
                ->all(),
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
            ],
        ]);
    }

    public function update(UserRoleUpdateRequest $request, User $user, UserRoleService $userRoleService): RedirectResponse
    {
        $userRoleService->syncRoles($request->user(), $user, $request->validated('role_ids', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User roles updated.')]);

        return to_route('users.index');
    }
}

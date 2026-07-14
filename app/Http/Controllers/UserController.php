<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The users.manage admin screen: a paginated, searchable/filterable list
 * of every user, full create/update/delete over accounts, and role
 * assignment (a user may hold more than one role, see
 * UserRoleService::syncRoles()). Create/update/delete all defer their
 * actual invariants (permission-tier separation, self-escalation, and
 * last-users.manage-holder lockout guards) to UserRoleService — the
 * single sanctioned entry point regardless of caller.
 */
class UserController extends Controller
{
    public function index(Request $request, UserRoleService $userRoleService): Response
    {
        $this->authorize(Permission::UsersManage->value);

        $search = $request->string('search')->toString() ?: null;
        $roleFilter = $request->string('role')->toString() ?: null;

        $users = User::query()
            ->with(['roles:id,name,label', 'office:id,office_name,school_id'])
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
                'office' => $user->office ? [
                    'id' => $user->office->id,
                    'name' => $user->office->office_name,
                    'school_id' => $user->office->school_id,
                ] : null,
                'roles' => $user->roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->label,
                ])->all(),
                'role_ids' => $user->roles->pluck('id')->all(),
                'is_self' => $user->is($request->user()),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'roles' => $this->assignableRoleOptions($request, $userRoleService),
            'offices' => Office::activeOptions(),
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
            ],
        ]);
    }

    public function create(Request $request, UserRoleService $userRoleService): Response
    {
        $this->authorize(Permission::UsersManage->value);

        return Inertia::render('users/Create', [
            'roles' => $this->assignableRoleOptions($request, $userRoleService),
            'offices' => Office::activeOptions(),
        ]);
    }

    public function store(UserStoreRequest $request, UserRoleService $userRoleService): RedirectResponse
    {
        $userRoleService->createUser(
            $request->user(),
            [
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'office_id' => $request->validated('office_id'),
            ],
            $request->validated('role_ids', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('users.index');
    }

    public function update(UserUpdateRequest $request, User $user, UserRoleService $userRoleService): RedirectResponse
    {
        $userRoleService->updateUser(
            $request->user(),
            $user,
            [
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'office_id' => $request->validated('office_id'),
            ],
            $request->validated('role_ids', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    public function destroy(Request $request, User $user, UserRoleService $userRoleService): RedirectResponse
    {
        $this->authorize(Permission::UsersManage->value);

        $userRoleService->deleteUser($request->user(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('users.index');
    }

    /**
     * Only roles the ACTING user could actually assign (their permission
     * set is a subset of the actor's own) — see
     * UserRoleService::assignableRolesFor()'s doc-comment. Keeps the UI
     * from ever offering an option the write side would reject (CRITICAL,
     * 2026-07 follow-up security review).
     *
     * @return array<int, array{id: int, name: string, label: string}>
     */
    private function assignableRoleOptions(Request $request, UserRoleService $userRoleService): array
    {
        return $userRoleService->assignableRolesFor($request->user())
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
            ])
            ->values()
            ->all();
    }
}

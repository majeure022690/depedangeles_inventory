<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission as PermissionEnum;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roles.manage admin screen: full CRUD over role definitions
 * (name/label/description + which permissions the role grants), gated on
 * Permission::RolesManage — deliberately a different, more sensitive
 * permission than users.manage (see that enum case's doc-comment).
 *
 * Authorization here answers only "does this actor hold roles.manage"
 * (RolePolicy). Every mutation-specific invariant — the protected
 * 'pending' role, "can't delete a role still assigned to users", and the
 * self-escalation/last-holder lockout guards on removing roles.manage/
 * users.manage from a role — is enforced by RoleService, which is the
 * single sanctioned entry point for create/update/delete regardless of
 * caller (mirrors UserRoleService's split for user role assignment).
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('label')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'user_count' => $role->users_count,
                'permission_count' => $role->permissions_count,
                'is_protected' => $role->isProtected(),
            ])
            ->all();

        return Inertia::render('roles/Index', [
            'roles' => $roles,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('roles/Create', [
            'permissions' => $this->permissionCatalog(),
        ]);
    }

    public function store(RoleStoreRequest $request, RoleService $roleService): RedirectResponse
    {
        $role = $roleService->create(
            $request->user(),
            [
                'name' => $request->validated('name'),
                'label' => $request->validated('label'),
                'description' => $request->validated('description'),
            ],
            $request->validated('permissions', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.edit', $role);
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        return Inertia::render('roles/Edit', [
            'role' => $this->roleProps($role),
            'permissions' => $this->permissionCatalog(),
        ]);
    }

    public function update(RoleUpdateRequest $request, Role $role, RoleService $roleService): RedirectResponse
    {
        $roleService->update(
            $request->user(),
            $role,
            [
                'name' => $request->validated('name'),
                'label' => $request->validated('label'),
                'description' => $request->validated('description'),
            ],
            $request->validated('permissions', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.edit', $role);
    }

    public function destroy(Request $request, Role $role, RoleService $roleService): RedirectResponse
    {
        $this->authorize('delete', $role);

        $roleService->delete($request->user(), $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function roleProps(Role $role): array
    {
        $role->loadCount('users');
        $role->load('permissions:id,name');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'user_count' => $role->users_count,
            'is_protected' => $role->isProtected(),
            'permissions' => $role->permissions->pluck('name')->all(),
        ];
    }

    /**
     * The fixed, code-defined permission catalog (App\Enums\Permission)
     * every role's checkbox list is built from — never derived from
     * `permissions` table rows, so it's identical regardless of what's
     * already been seeded/granted.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function permissionCatalog(): array
    {
        return collect(PermissionEnum::cases())
            ->map(fn (PermissionEnum $permission) => [
                'value' => $permission->value,
                'label' => $permission->label(),
            ])
            ->all();
    }
}

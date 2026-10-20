<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreRoleRequest;
use App\Http\Requests\Management\UpdateRoleRequest;
use App\Http\Resources\Management\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The roles.manage admin screen. Reading roles is also open to
 * users.manage holders (they assign roles). Every mutation invariant
 * (protected `pending` role, no deleting an assigned role, lockout and
 * self-escalation guards) lives in RoleService, which also writes the
 * `role.*` activity rows.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()->withCount('permissions')->orderBy('label')->get();
        $userCounts = Role::assignedUsersCountByRoleId($roles->pluck('id'));
        $roles->each(fn (Role $role) => $role->setAttribute('user_count', (int) $userCounts->get($role->id, 0)));

        return RoleResource::collection($roles)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->resource($role)
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function store(StoreRoleRequest $request, RoleService $roleService): JsonResponse
    {
        $role = $roleService->create(
            $request->user(),
            [
                'name' => $request->validated('name'),
                'label' => $request->validated('label'),
                'description' => $request->validated('description'),
                'data_scope' => $request->validated('data_scope', DataScope::Own->value),
            ],
            $request->validated('permissions', []),
        );

        return $this->resource($role)
            ->additional(['message' => 'Role created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role, RoleService $roleService): JsonResponse
    {
        $this->authorize('update', $role);

        $roleService->update(
            $request->user(),
            $role,
            [
                'name' => $request->validated('name'),
                'label' => $request->validated('label'),
                'description' => $request->validated('description'),
                'data_scope' => $request->validated('data_scope', $role->data_scope->value),
            ],
            $request->validated('permissions', []),
        );

        return $this->resource($role)
            ->additional(['message' => 'Role updated.'])
            ->response();
    }

    /**
     * The protected `pending` role and roles still assigned to users
     * cannot be deleted: RoleService refuses, answered here as 409.
     */
    public function destroy(Request $request, Role $role, RoleService $roleService): Response|JsonResponse
    {
        $this->authorize('delete', $role);

        try {
            $roleService->delete($request->user(), $role);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 409);
        }

        return response()->noContent();
    }

    private function resource(Role $role): RoleResource
    {
        $role = $role->fresh(['permissions']) ?? $role;
        $role->loadCount('permissions')->setAttribute('user_count', $role->assignedUsersCount());

        return new RoleResource($role);
    }
}

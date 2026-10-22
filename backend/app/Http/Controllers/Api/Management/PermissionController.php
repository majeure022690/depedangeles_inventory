<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission as PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PermissionResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * The permission catalog for the role editor: the seeded permission rows,
 * in App\Enums\Permission's declaration order, labelled and grouped by
 * the enum.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        // Same audience as reading roles (roles.view or users.edit).
        $this->authorize('viewAny', Role::class);

        $order = array_flip(array_map(fn (PermissionEnum $case) => $case->value, PermissionEnum::cases()));

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->get()
            ->sortBy(fn (Permission $permission) => $order[$permission->name] ?? PHP_INT_MAX)
            ->values();

        return PermissionResource::collection($permissions)
            ->additional(['message' => 'Success'])
            ->response();
    }
}

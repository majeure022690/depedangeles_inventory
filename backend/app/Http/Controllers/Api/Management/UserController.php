<?php

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\ResetTwoFactorRequest;
use App\Http\Requests\Management\StoreUserRequest;
use App\Http\Requests\Management\UpdateUserRequest;
use App\Http\Resources\Management\UserResource;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\PrimeUserSync;
use App\Services\UserRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The users.manage admin screen. Every account/role invariant (self-edit
 * lockout, permission-tier separation, last users.manage holder) lives
 * in UserRoleService, the single entry point for create/update/delete.
 *
 * Audit: account rows come from User's LogsActivity, role-set changes are
 * `user.roles_changed` (both written by UserRoleService).
 */
class UserController extends Controller
{
    use ResolvesListParameters;

    /**
     * @var array<string, string>
     */
    private const SORTABLE = [
        'name' => 'name',
        'email' => 'email',
        'is_active' => 'is_active',
        'created_at' => 'created_at',
    ];

    public function index(Request $request, UserRoleService $userRoleService, PrimeUserSync $primeSync): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $primeSync->runThrottled();

        $search = $request->string('search')->trim()->toString();
        $role = $request->string('role')->toString();

        $query = User::query()
            ->with(['roles:id,name,label', 'office:id,office_name,school_id'])
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"),
            ))
            ->when($role !== '', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', $role)))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('office_id'), fn ($query) => $query->where('office_id', $request->integer('office_id')));

        if (! $this->applySort($query, $request, self::SORTABLE)) {
            $query->orderBy('name');
        }

        $users = $query->paginate($this->perPage($request))->withQueryString();
        $this->flagManageable($request->user(), $users->getCollection(), $userRoleService);

        return UserResource::collection($users)
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Only roles the acting user may actually assign (their permission
     * set is a subset of the actor's own), so the UI never offers one the
     * write side would reject.
     */
    public function options(Request $request, UserRoleService $userRoleService): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json([
            'data' => [
                'roles' => $userRoleService->assignableRolesFor($request->user())
                    ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'label' => $role->label])
                    ->values()
                    ->all(),
                'offices' => Office::activeOptions(),
            ],
            'message' => 'Success',
        ]);
    }

    public function store(StoreUserRequest $request, UserRoleService $userRoleService): JsonResponse
    {
        // User creation is disabled - all users are managed through PRIME
        return response()->json([
            'message' => 'User creation is disabled. Users are managed through PRIME.',
        ], 403);
    }

    public function update(UpdateUserRequest $request, User $user, UserRoleService $userRoleService): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->safe();

        $userRoleService->updateUser(
            $request->user(),
            $user,
            [
                'office_id' => $validated->filled('office_id') ? $validated->integer('office_id') : null,
                'is_active' => $validated->has('is_active') ? $validated->boolean('is_active') : $user->is_active,
            ],
            [$validated->integer('role_id')],
        );

        $user = $user->fresh(['roles:id,name,label', 'office:id,office_name,school_id']) ?? $user;
        $this->flagManageable($request->user(), collect([$user]), $userRoleService);

        return new UserResource($user)
            ->additional(['message' => 'User updated.'])
            ->response();
    }

    public function destroy(Request $request, User $user, UserRoleService $userRoleService): JsonResponse
    {
        // User deletion is disabled - all users are managed through PRIME
        return response()->json([
            'message' => 'User deletion is disabled. Users are managed through PRIME.',
        ], 403);
    }

    /**
     * Admin reset for a lost authenticator: the user enrolls again on
     * their next login (2FA is mandatory).
     */
    public function resetTwoFactor(ResetTwoFactorRequest $request, User $user, UserRoleService $userRoleService): JsonResponse
    {
        $this->authorize('resetTwoFactor', $user);

        $userRoleService->resetTwoFactor($request->user(), $user);

        Log::channel('security')->info('Two-factor authentication reset by admin', [
            'user_id' => $user->id,
            'admin_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Two-factor authentication has been reset for this user.']);
    }

    /**
     * Mirrors UserPolicy: `can_edit` is update, `can_delete` is always false (deletion disabled),
     * `can_reset_two_factor` is resetTwoFactor, so the SPA can hide
     * actions the Policy would refuse.
     *
     * @param  Collection<int, User>  $users
     */
    private function flagManageable(User $actor, Collection $users, UserRoleService $userRoleService): void
    {
        $exceeding = $userRoleService->idsExceedingActor($actor, $users->pluck('id')->all());
        $canEdit = $actor->hasPermissionTo(Permission::UsersEdit);
        $canResetTwoFactor = $actor->hasPermissionTo(Permission::UsersResetTwoFactor);

        $users->each(function (User $user) use ($actor, $exceeding, $canEdit, $canResetTwoFactor): void {
            $withinTier = ! in_array($user->id, $exceeding, true);

            $user->setAttribute('can_edit', $canEdit && $withinTier);
            $user->setAttribute('can_delete', false); // User deletion is disabled - all users are synced from PRIME
            $user->setAttribute('can_reset_two_factor', $canResetTwoFactor && $withinTier && ! $actor->is($user) && $user->hasTwoFactorEnabled());
        });
    }
}

<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => $this->office ? [
                'id' => $this->office->id,
                'office_name' => $this->office->office_name,
                'school_id' => $this->office->school_id,
            ] : null),
            'is_active' => $this->is_active,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'role_ids' => $this->whenLoaded('roles', fn () => $this->roles->pluck('id')),
            // For the users admin list, which shows labels, not machine names.
            'role_details' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Model $role) => [
                'id' => $role->getKey(),
                'name' => $role->getAttribute('name'),
                'label' => $role->getAttribute('label'),
            ])->values()),
            // Feeds the SPA's AuthContext.can(); the server re-checks every
            // request regardless. Only when direct permissions were loaded
            // too (the auth endpoints load roles.permissions + permissions),
            // so list endpoints don't resolve every user's permission set.
            'permissions' => $this->when(
                $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name'),
            ),
            'is_self' => $request->user()?->is($this->resource) ?? false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

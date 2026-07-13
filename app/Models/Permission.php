<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The Eloquent-side materialization of App\Enums\Permission (that enum,
 * not this table, is the authoritative list of what permissions exist —
 * see RolePermissionSeeder). This model exists only so roles can be
 * attached to permissions via a normal FK pivot.
 */
#[Fillable(['name'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

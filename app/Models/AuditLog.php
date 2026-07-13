<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Minimal, append-only audit trail — proportionate to this project's
 * size, not a general activity-log framework (see migration doc-comment).
 * Write to it exclusively through static::record(); nothing else should
 * insert here directly, and nothing ever updates or deletes a row.
 */
#[Fillable(['actor_id', 'action', 'subject_type', 'subject_id', 'properties', 'created_at'])]
class AuditLog extends Model
{
    /**
     * No `updated_at` column exists — rows are append-only.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The single entry point for writing an audit entry. `$actorId`
     * defaults to the currently authenticated user (the one performing
     * the action); pass it explicitly for console/system-initiated
     * changes where there is no authenticated actor.
     *
     * `actor_id` is a nullable, nullOnDelete FK (see migration) so the
     * audit trail doesn't get blocked by a user being removed — but that
     * same nullOnDelete means a self-deleted account (ProfileController@
     * destroy requires only the user's own current password; no admin
     * review) would otherwise erase its own attribution from every row
     * it ever produced, destroying non-repudiation. To survive that, the
     * actor's name/email are snapshotted into `properties.actor_snapshot`
     * at write time, when the FK is still guaranteed valid — the audit
     * row stays meaningful even after `actor_id` later nulls out.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        ?int $actorId = null,
    ): self {
        // Prefer the already-loaded authenticated user (no extra query)
        // when no explicit actor was passed; only fall back to a lookup
        // for explicitly-passed actor IDs (e.g. console/system callers).
        /** @var User|null $actor */
        $actor = $actorId === null
            ? auth()->user()
            : (auth()->id() === $actorId ? auth()->user() : User::find($actorId));

        if ($actor) {
            $properties['actor_snapshot'] = [
                'name' => $actor->name,
                'email' => $actor->email,
            ];
        }

        return static::create([
            'actor_id' => $actorId ?? $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'created_at' => now(),
        ]);
    }
}

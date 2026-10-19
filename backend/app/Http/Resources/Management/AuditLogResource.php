<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * rwatch's shape plus `actor` and `properties`. `actor` is the live
 * causer when the account still exists, otherwise the
 * `properties.actor_snapshot` written when the row was logged, otherwise
 * null (system/console rows).
 *
 * @mixin Activity
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $properties = $this->properties?->all() ?? [];

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'subject_type' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'actor' => $this->actor($properties),
            'changes' => [
                'old' => $this->attribute_changes?->get('old'),
                'attributes' => $this->attribute_changes?->get('attributes'),
            ],
            'properties' => (object) $properties,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{id: int|null, name: string|null, email: string|null}|null
     */
    private function actor(array $properties): ?array
    {
        $causer = $this->relationLoaded('causer') ? $this->causer : null;

        if ($causer instanceof User) {
            return ['id' => $causer->id, 'name' => $causer->name, 'email' => $causer->email];
        }

        $snapshot = $properties['actor_snapshot'] ?? null;

        if (! is_array($snapshot)) {
            return null;
        }

        return [
            'id' => $this->causer_id !== null ? (int) $this->causer_id : null,
            'name' => $snapshot['name'] ?? null,
            'email' => $snapshot['email'] ?? null,
        ];
    }
}

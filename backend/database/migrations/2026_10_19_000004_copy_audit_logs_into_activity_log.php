<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies every `audit_logs` row into `activity_log` in id order, using the
 * mapping in decoupled-spa-stack-plan.md (Phase 1, migration 4). Each copy
 * is tagged with `properties.migrated_from_audit_log_id`, which is how
 * down() finds them and how QA verifies the copy. `audit_logs` itself is
 * left untouched; it is dropped separately in Phase 7.
 */
return new class extends Migration
{
    private const USER_MORPH = 'App\\Models\\User';

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('audit_logs')->chunkById(500, function ($rows) {
                DB::table('activity_log')->insert(
                    $rows->map(fn (stdClass $row) => $this->mapRow($row))->all(),
                );
            });
        });
    }

    public function down(): void
    {
        DB::table('activity_log')
            ->where('log_name', 'default')
            ->whereNotNull('properties->migrated_from_audit_log_id')
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(stdClass $row): array
    {
        $properties = $row->properties === null ? [] : json_decode($row->properties, true);
        $properties = is_array($properties) ? $properties : [];

        $attributeChanges = $this->extractAttributeChanges($properties);
        $properties['migrated_from_audit_log_id'] = $row->id;

        return [
            'log_name' => 'default',
            'description' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'event' => preg_match('/^[^.]+\.(created|updated|deleted)$/', $row->action, $m) ? $m[1] : $row->action,
            'causer_type' => $row->actor_id !== null ? self::USER_MORPH : null,
            'causer_id' => $row->actor_id,
            'attribute_changes' => $attributeChanges === null ? null : json_encode($attributeChanges, JSON_THROW_ON_ERROR),
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'created_at' => $row->created_at,
            'updated_at' => $row->created_at,
        ];
    }

    /**
     * Moves the diff-shaped keys out of $properties into activitylog's
     * `{old, attributes}` shape. Only the first shape present is used; the
     * writers never combined them.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private function extractAttributeChanges(array &$properties): ?array
    {
        if (isset($properties['changes']) && is_array($properties['changes'])) {
            $old = [];
            $new = [];

            foreach ($properties['changes'] as $key => $change) {
                $old[$key] = is_array($change) ? ($change['old'] ?? null) : null;
                $new[$key] = is_array($change) ? ($change['new'] ?? null) : $change;
            }

            unset($properties['changes']);

            return ['old' => $old, 'attributes' => $new];
        }

        if (isset($properties['attributes']) && is_array($properties['attributes'])) {
            $attributes = $properties['attributes'];
            unset($properties['attributes']);

            return ['attributes' => $attributes];
        }

        if (array_key_exists('before', $properties) || array_key_exists('after', $properties)) {
            $changes = array_filter([
                'old' => $properties['before'] ?? null,
                'attributes' => $properties['after'] ?? null,
            ], fn ($value) => $value !== null);
            unset($properties['before'], $properties['after']);

            return $changes === [] ? null : $changes;
        }

        return null;
    }
};

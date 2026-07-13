<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Enums\PersonnelLibraryType;
use App\Enums\StakeholderLibraryType;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a `config/reference-data.php` registry key (the `{table}` URL
 * segment used by ReferenceDataController/ReferenceDataUpdateRequest —
 * see the lookup-normalization ADR's Question 3, docs/architecture-
 * decisions/lookup-normalization.md) into its registry entry or an actual
 * model row.
 *
 * Extracted here, rather than duplicated as a protected method on
 * ReferenceDataController, because ReferenceDataUpdateRequest::authorize()
 * needs the exact same resolution independently — FormRequest::authorize()
 * runs before the controller method body, so it cannot simply call back
 * into the controller for the row it needs to check `can('update', $row)`
 * against.
 *
 * Deliberately does NOT build a class name from the raw `{table}` route
 * segment (e.g. `Str::studly($table)` -> `app\Models\{$table}`) — every
 * resolvable model class comes from the static config array, keyed by a
 * fixed, known-safe set of strings. An unrecognized `{table}` value is a
 * 404, never an attempt to instantiate an arbitrary class from user input.
 */
final class ReferenceDataResolver
{
    /**
     * Reconstructs the registry entry as an explicit literal array (rather
     * than returning `$entry` from `config()` as-is) so Larastan can
     * statically verify the shape below instead of trusting an
     * `array<mixed, mixed>` read out of config — `config()` itself has no
     * way to know its return type is this specific shape.
     *
     * `type_enum` is normalized to always be present (null for Tier 1,
     * which has no discriminator column) rather than an optional key —
     * config/reference-data.php still omits it entirely for Tier 1
     * entries; this is purely a static-typing normalization, not a change
     * to the registry format itself.
     *
     * @return array{label: string, model: class-string<Model>, tier: int, type_enum: class-string<EquipmentLibraryType|PersonnelLibraryType|StakeholderLibraryType|ConnectivityLibraryType>|null}
     */
    public static function entry(string $table): array
    {
        $entry = config("reference-data.{$table}");

        if (! is_array($entry)) {
            throw new NotFoundHttpException("Unknown reference-data table [{$table}].");
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['model'];

        /** @var class-string<EquipmentLibraryType|PersonnelLibraryType|StakeholderLibraryType|ConnectivityLibraryType>|null $typeEnum */
        $typeEnum = $entry['type_enum'] ?? null;

        return [
            'label' => (string) $entry['label'],
            'model' => $modelClass,
            'tier' => (int) $entry['tier'],
            'type_enum' => $typeEnum,
        ];
    }

    public static function resolveRow(string $table, int|string $id): Model
    {
        $modelClass = self::entry($table)['model'];

        return $modelClass::findOrFail($id);
    }
}

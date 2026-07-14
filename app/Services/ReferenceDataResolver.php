<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Enums\PersonnelLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\Equipment;
use App\Models\EquipmentLibrary;
use App\Models\EquipmentTransaction;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspSpeedTest;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
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
 *
 * Also owns guardDeletable() — the hard-delete usage-safety check for
 * ReferenceDataController::destroy(). Lives here rather than on the
 * controller for the same reason the rest of this class exists: it's
 * genuinely non-trivial (a fixed table.column usage map per Tier 2 type,
 * plus the one Tier 1 JSON-column exception for isp_providers), so a
 * dedicated Service method keeps the controller thin per CLAUDE.md.
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

    /**
     * Hard-delete safety guard, called by ReferenceDataController::destroy()
     * BEFORE it attempts the delete. Split by tier, per the `database`
     * agent's safety analysis:
     *
     *  - Tier 1 (9 tables): every consuming column is already a real FK
     *    with `restrictOnDelete()` — that constraint is authoritative and
     *    atomic, so this method does NOT pre-query Tier 1 usage. The
     *    controller instead attempts the delete and catches the resulting
     *    QueryException (SQLSTATE 23000).
     *  - EXCEPTION: `isp-providers` is also referenced via two
     *    unenforced JSON-array columns —
     *    internet_connectivity_surveys.available_isps/.subscribed_isps,
     *    each storing isp_providers.id values (see IspProvider's own
     *    doc-comment) — so it gets an explicit manual check here, the
     *    only Tier 1 table that does.
     *  - Tier 2 (4 tables): no FK enforcement at all (consumer columns
     *    stayed validated strings/JSON-id-arrays per the ADR's Question
     *    2), so every row's usage must be checked explicitly against the
     *    fixed table.column map below before allowing the delete.
     *
     * Throws the same friendly ValidationException either way, so
     * ReferenceDataController's single catch site (Tier 1 FK violations)
     * and this guard (everything else) present one consistent error to
     * the admin regardless of which mechanism caught it.
     */
    public static function guardDeletable(string $table, Model $row): void
    {
        if ($table === 'isp-providers' && self::ispProviderReferencedInSurveys((int) $row->getKey())) {
            self::throwStillInUse();
        }

        if (self::tierTwoRowInUse($row)) {
            self::throwStillInUse();
        }
    }

    private static function throwStillInUse(): never
    {
        throw ValidationException::withMessages([
            'delete' => 'This value is still in use and cannot be deleted — deactivate it instead.',
        ]);
    }

    private static function ispProviderReferencedInSurveys(int $id): bool
    {
        return InternetConnectivitySurvey::whereJsonContains('available_isps', $id)
            ->orWhereJsonContains('subscribed_isps', $id)
            ->exists();
    }

    /**
     * Dispatches by the row's actual class via `instanceof`, not by the
     * `{table}` route-segment string — a real narrowing construct (unlike
     * a string match on $table) lets each per-domain helper below declare
     * its true model parameter type instead of the generic Model, so
     * `$row->type`/`$row->value` resolve to real types (EquipmentLibraryType,
     * string, ...) instead of `mixed`. Returns false for Tier 1 rows
     * (none of the four `instanceof` checks match), which is correct:
     * guardDeletable() only wants this to ever say "yes, still in use"
     * for the four Tier 2 tables — Tier 1 safety is the FK/QueryException
     * path instead (see this method's own doc-comment).
     */
    private static function tierTwoRowInUse(Model $row): bool
    {
        return match (true) {
            $row instanceof EquipmentLibrary => self::equipmentLibraryInUse($row),
            $row instanceof PersonnelLibrary => self::personnelLibraryInUse($row),
            $row instanceof StakeholderLibrary => self::stakeholderLibraryInUse($row),
            $row instanceof ConnectivityLibrary => self::connectivityLibraryInUse($row),
            default => false,
        };
    }

    private static function equipmentLibraryInUse(EquipmentLibrary $row): bool
    {
        $value = (string) $row->value;

        return match ($row->type) {
            EquipmentLibraryType::UnitOfMeasure => Equipment::where('uom', $value)->exists(),
            EquipmentLibraryType::DispositionStatus => Equipment::where('disposition_status', $value)->exists(),
            EquipmentLibraryType::DcpPackage => Equipment::where('dcp_package', $value)->exists(),
            EquipmentLibraryType::ModeOfAcquisition => Equipment::where('mode_acquisition', $value)->exists()
                || IspAccount::where('mode_of_acquisition', $value)->exists(),
            EquipmentLibraryType::SourceOfAcquisition => Equipment::where('source_acquisition', $value)->exists()
                || IspAccount::where('source_of_acquisition', $value)->exists(),
            EquipmentLibraryType::SourceOfFunds => Equipment::where('source_fund', $value)->exists()
                || IspAccount::where('fund_source', $value)->exists(),
            EquipmentLibraryType::AllotmentClass => Equipment::where('allotment_class', $value)->exists(),
            EquipmentLibraryType::SupportingDocumentType => EquipmentTransaction::where('supporting_documents1', $value)
                ->orWhere('supporting_documents2', $value)
                ->exists(),
            EquipmentLibraryType::TransactionType => EquipmentTransaction::where('transaction_type', $value)->exists()
                || StakeholderProfile::where('transaction_type', $value)->exists(),
        };
    }

    private static function personnelLibraryInUse(PersonnelLibrary $row): bool
    {
        $value = (string) $row->value;
        $id = (int) $row->getKey();

        return match ($row->type) {
            PersonnelLibraryType::NameSuffix => Personnel::where('suffix', $value)->exists(),
            PersonnelLibraryType::TeachersFundingSource => Personnel::whereJsonContains('fund_source', $id)->exists(),
            PersonnelLibraryType::CauseOfSeparation => Personnel::where('separation_cause', $value)->exists(),
        };
    }

    /**
     * `CommunityContext` has NO known consumer column anywhere in the
     * schema — it's kept here (rather than silently omitted from the
     * match, which would throw UnhandledMatchError) so that fact is
     * explicit and reviewable, not an oversight. Deletes for this type
     * are therefore never blocked. Flagged upstream to the architect/
     * product owner as a likely-orphaned field rather than assumed away.
     */
    private static function stakeholderLibraryInUse(StakeholderLibrary $row): bool
    {
        $value = (string) $row->value;
        $id = (int) $row->getKey();

        return match ($row->type) {
            StakeholderLibraryType::GovernanceLevel => StakeholderProfile::where('governance_level', $value)->exists(),
            StakeholderLibraryType::CommunityContext => false,
            StakeholderLibraryType::TypeOfAccessRoad => StakeholderProfile::whereJsonContains('access_paths', $id)->exists(),
            StakeholderLibraryType::ByTransportation => StakeholderProfile::whereJsonContains('transportation_options', $id)->exists(),
            StakeholderLibraryType::NearbyInstitution => StakeholderProfile::whereJsonContains('nearby_institutions', $id)->exists(),
            StakeholderLibraryType::CommunityEngagement => StakeholderProfile::whereJsonContains('community_engagement', $id)->exists(),
            StakeholderLibraryType::SourceOfElectricity => InternetConnectivitySurvey::whereJsonContains('electricity_sources', $id)->exists(),
        };
    }

    private static function connectivityLibraryInUse(ConnectivityLibrary $row): bool
    {
        $value = (string) $row->value;
        $id = (int) $row->getKey();

        return match ($row->type) {
            ConnectivityLibraryType::IspConnectionType => IspAccount::where('isp_connection_type', $value)->exists(),
            ConnectivityLibraryType::SubscriptionType => IspAccount::where('subscription_type', $value)->exists(),
            ConnectivityLibraryType::PurposeOfSubscription => IspAccount::where('purpose_of_subscription', $value)->exists(),
            ConnectivityLibraryType::SignalQuality => IspSpeedTest::where('signal_quality', $value)->exists()
                || IspAccount::where('rate_connectivity_admin_areas', $value)->exists()
                || IspAccount::where('rate_connectivity_classroom', $value)->exists()
                || IspAccount::where('overall_signal_quality', $value)->exists()
                || InternetConnectivitySurvey::where('mobile_data_quality', $value)->exists(),
            ConnectivityLibraryType::MobileNetworkSignal => InternetConnectivitySurvey::whereJsonContains('mobile_signal_types', $id)->exists(),
            ConnectivityLibraryType::CoverageArea => InternetConnectivitySurvey::whereJsonContains('coverage_areas', $id)->exists(),
            ConnectivityLibraryType::SchoolLevelCoverage => IspAccount::where('school_area_coverage', $value)->exists(),
        };
    }
}

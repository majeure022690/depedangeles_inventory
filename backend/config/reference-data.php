<?php

declare(strict_types=1);

use App\Enums\ConnectivityLibraryType;
use App\Enums\EquipmentLibraryType;
use App\Enums\PersonnelLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\Brand;
use App\Models\ConnectivityLibrary;
use App\Models\EquipmentCategory;
use App\Models\EquipmentClassification;
use App\Models\EquipmentCondition;
use App\Models\EquipmentLibrary;
use App\Models\IspProvider;
use App\Models\ItemType;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use App\Models\StakeholderLibrary;

/**
 * The single source of truth for "what reference-data tables exist" —
 * built per the lookup-normalization ADR's Question 3
 * (docs/architecture-decisions/lookup-normalization.md), which explicitly
 * calls for "a small static registry ... not a revived global enum" so
 * the admin index (App\Http\Controllers\ReferenceDataController) isn't 13
 * independently-hardcoded page routes.
 *
 * One entry per physical reference table, keyed by the kebab-case URL
 * segment ReferenceDataController resolves `{table}` route parameters
 * against (App\Http\Controllers\ReferenceDataController::registryEntry()).
 *
 * Every entry has:
 *  - `label`      Human-readable name for the admin UI (e.g. "Item Types").
 *  - `model`      FQCN of the Eloquent model backing this table.
 *  - `tier`       1 (dedicated entity table, no discriminator) or
 *                 2 (domain-grouped library table, discriminated by `type`).
 *
 * Tier 2 entries additionally have:
 *  - `type_enum`  FQCN of the backed enum discriminating that table's
 *                 `type` column (e.g. EquipmentLibraryType for
 *                 equipment_libraries) — lets the admin UI filter/group
 *                 rows by type within that one physical table.
 *
 * Tier 1 entries deliberately have no `type_enum` key at all (not even
 * null) — they're single-concept tables with no discriminator, so the key
 * simply doesn't apply; ReferenceDataController and the admin UI treat its
 * absence, not a null value, as "this table isn't type-discriminated."
 */
return [

    // Tier 1 — dedicated entity tables, no `type` discriminator.
    'item-types' => [
        'label' => 'Item Types',
        'model' => ItemType::class,
        'tier' => 1,
    ],
    'brands' => [
        'label' => 'Brands',
        'model' => Brand::class,
        'tier' => 1,
    ],
    'equipment-categories' => [
        'label' => 'Equipment Categories',
        'model' => EquipmentCategory::class,
        'tier' => 1,
    ],
    'equipment-classifications' => [
        'label' => 'Equipment Classifications',
        'model' => EquipmentClassification::class,
        'tier' => 1,
    ],
    'equipment-conditions' => [
        'label' => 'Equipment Conditions',
        'model' => EquipmentCondition::class,
        'tier' => 1,
    ],
    'positions' => [
        'label' => 'Positions',
        'model' => Position::class,
        'tier' => 1,
    ],
    'ro-offices' => [
        'label' => 'RO Offices',
        'model' => RoOffice::class,
        'tier' => 1,
    ],
    'sdo-offices' => [
        'label' => 'SDO Offices',
        'model' => SdoOffice::class,
        'tier' => 1,
    ],
    'isp-providers' => [
        'label' => 'ISP Providers',
        'model' => IspProvider::class,
        'tier' => 1,
    ],

    // Tier 2 — domain-grouped library tables, discriminated by `type`.
    'equipment-libraries' => [
        'label' => 'Equipment Libraries',
        'model' => EquipmentLibrary::class,
        'tier' => 2,
        'type_enum' => EquipmentLibraryType::class,
    ],
    'personnel-libraries' => [
        'label' => 'Personnel Libraries',
        'model' => PersonnelLibrary::class,
        'tier' => 2,
        'type_enum' => PersonnelLibraryType::class,
    ],
    'stakeholder-libraries' => [
        'label' => 'Stakeholder Libraries',
        'model' => StakeholderLibrary::class,
        'tier' => 2,
        'type_enum' => StakeholderLibraryType::class,
    ],
    'connectivity-libraries' => [
        'label' => 'Connectivity Libraries',
        'model' => ConnectivityLibrary::class,
        'tier' => 2,
        'type_enum' => ConnectivityLibraryType::class,
    ],

];

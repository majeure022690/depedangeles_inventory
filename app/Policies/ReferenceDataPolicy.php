<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE shared Policy for all 13 reference-data tables from the
 * lookup-normalization ADR (docs/architecture-decisions/lookup-normalization.md)
 * — the 9 Tier 1 dedicated entity tables (ItemType, Brand,
 * EquipmentCategory, EquipmentClassification, EquipmentCondition,
 * Position, RoOffice, SdoOffice, IspProvider) and the 4 Tier 2
 * domain-grouped library tables (EquipmentLibrary, PersonnelLibrary,
 * StakeholderLibrary, ConnectivityLibrary).
 *
 * Design decision: one shared Policy class, not 13 per-model Policies.
 * The ADR is explicit that these 13 tables are "one kind of low-stakes
 * reference-data admin capability" (Question 1's rejection of 35
 * dedicated tables, and Step 2 item 8's permission recommendation) —
 * exactly the reasoning that already produced Permission::ReferenceDataManage
 * as ONE umbrella permission instead of 13 fragmented ones. A Policy per
 * model would silently reintroduce the fragmentation the permission
 * design just rejected: 13 near-identical classes, each with five
 * methods that all do the same single `hasPermissionTo(ReferenceDataManage)`
 * check, differing only in which model class they're bound to. That's
 * pure ceremony with no authorization behavior it actually buys — there
 * is no reference-data table today where "can manage brands" needs to
 * differ from "can manage item types." This mirrors LookupPolicy's own
 * existing shape (one permission, one Policy, no per-row nuance) scaled
 * up from 1 model to 13, and matches how Permission::UsersManage/
 * UserController skip a Policy class entirely for a single-capability
 * admin screen with no model-instance nuance — the only reason this one
 * gets an actual Policy class (rather than bare
 * `$this->authorize(Permission::ReferenceDataManage->value)` calls like
 * UserController's) is that Backend's forthcoming controller(s) need
 * model-scoped authorization (`$this->authorize('update', $itemType)`)
 * across 13 different model classes, which requires a Policy bound to
 * each — see AppServiceProvider::configureAuthorization() for the
 * explicit Gate::policy() registration this Policy needs (Laravel's
 * naming-convention auto-discovery only finds `{Model}Policy`, so a
 * shared class must be registered per model explicitly).
 *
 * If a genuine per-table authorization difference ever surfaces (e.g. a
 * future requirement where positions can be managed by HR staff but
 * brands cannot), that's a real trigger to split this into per-model
 * Policies then, on evidence — not preemptively now.
 */
class ReferenceDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function view(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function update(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }

    public function delete(User $user, Model $referenceRow): bool
    {
        return $user->hasPermissionTo(Permission::ReferenceDataManage);
    }
}

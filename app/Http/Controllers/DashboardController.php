<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\IspAccount;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard has no dedicated permission/Policy of its own — it's a
 * personalized summary of whatever the requesting user is otherwise
 * allowed to see, not a resource with its own access rule. Each section
 * below is therefore independently gated by the same view permission
 * that already governs that resource's own index page
 * (Permission::EquipmentView, ::PersonnelView, ::IspAccountsView) and
 * simply omitted from the response — never a blanket 403 — for a user
 * who holds none of them (e.g. the zero-permission 'pending' role).
 *
 * Gating uses User::hasPermissionTo() directly rather than
 * $user->can()/$this->authorize(): the Gate::before hook in
 * AppServiceProvider::configureAuthorization() writes an
 * `authorization.denied` AuditLog row every time a permission-string
 * ability is checked and fails. That's the right behavior for an actual
 * attempted action (e.g. hitting equipment.destroy without
 * equipment.delete), but a dashboard visit isn't an attempted action —
 * it's routine, and every section a viewer merely lacks access to would
 * otherwise write a spurious "denied" row on every single page load.
 */
class DashboardController extends Controller
{
    /**
     * How many days out a warranty/contract end date counts as "expiring
     * soon" for the dashboard's at-a-glance alerts.
     */
    private const EXPIRING_SOON_DAYS = 30;

    /**
     * Rows shown in the recent-activity feed.
     */
    private const RECENT_ACTIVITY_LIMIT = 10;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $props = [];

        if ($user->hasPermissionTo(Permission::EquipmentView)) {
            $props['equipment'] = $this->equipmentSummary();
            $props['recentActivity'] = $this->recentEquipmentActivity();
        }

        if ($user->hasPermissionTo(Permission::PersonnelView)) {
            $props['personnel'] = $this->personnelSummary();
        }

        if ($user->hasPermissionTo(Permission::IspAccountsView)) {
            $props['ispAccounts'] = $this->ispAccountsSummary();
        }

        return Inertia::render('Dashboard', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentSummary(): array
    {
        $now = now();

        return [
            'total' => Equipment::query()->count(),
            // `condition`/`category` are Tier 1 FKs now (lookup-
            // normalization ADR, Step 2 — Equipment only): must join to
            // the reference table and group by its display name, not the
            // raw id, or this breakdown would show ids instead of
            // human-readable labels. `disposition_status` stays Tier 2
            // (a plain validated string column) — unchanged, per the
            // ADR's explicit note not to convert it.
            'by_condition' => $this->countsByReferenceJoin('equipment_conditions', 'equipment_condition_id'),
            'by_category' => $this->countsByReferenceJoin('equipment_categories', 'equipment_category_id'),
            'by_disposition_status' => $this->countsByRawSelect('disposition_status as value, count(*) as aggregate', 'disposition_status'),
            'total_acquisition_cost' => (float) Equipment::query()->sum('acquisition_cost'),
            'non_functional_count' => Equipment::query()->where('non_functional', true)->count(),
            'warranty_expiring_soon_count' => Equipment::query()
                ->where('under_warranty', true)
                ->whereBetween('end_warranty_date', [$now->toDateString(), $now->addDays(self::EXPIRING_SOON_DAYS)->toDateString()])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personnelSummary(): array
    {
        return [
            'active_count' => Personnel::query()->where('inactive', false)->count(),
            'inactive_count' => Personnel::query()->where('inactive', true)->count(),
            'oic_count' => Personnel::query()->where('oic', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ispAccountsSummary(): array
    {
        $now = now();

        return [
            'total_monthly_cost' => (float) IspAccount::query()->where('inactive_contract', false)->sum('cost_per_month'),
            // "Expiring soon" is meaningful only for a contract that's
            // still active — a lapsed/deactivated one isn't something
            // anyone needs to be alerted to renew.
            'contracts_expiring_soon_count' => IspAccount::query()
                ->where('inactive_contract', false)
                ->whereBetween('contract_end_date', [$now->toDateString(), $now->addDays(self::EXPIRING_SOON_DAYS)->toDateString()])
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentEquipmentActivity(): array
    {
        return EquipmentTransaction::query()
            ->with([
                // 'item' is Equipment's legacy Tier 1 string column — no
                // longer written by the app post-cutover (lookup-
                // normalization ADR, Step 2), so it's read via the
                // itemType relationship instead, same as everywhere else
                // in EquipmentController.
                'equipment:id,property_no,item_type_id',
                'equipment.itemType:id,name',
                'accountableOfficer:id,full_name',
                'endUser:id,full_name',
                'recordedBy:id,name',
            ])
            ->latest('created_at')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (EquipmentTransaction $transaction) => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'equipment' => $transaction->equipment ? [
                    'id' => $transaction->equipment->id,
                    'property_no' => $transaction->equipment->property_no,
                    'item' => $transaction->equipment->itemType?->name,
                ] : null,
                'accountable_officer' => $transaction->accountableOfficer?->only(['id', 'full_name']),
                'end_user' => $transaction->endUser?->only(['id', 'full_name']),
                'recorded_by' => $transaction->recordedBy?->only(['id', 'name']),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Grouped {value, count} rows for equipment, in descending count
     * order — the shape every dashboard breakdown chart/list on the
     * frontend consumes. Queries the `equipment` table directly via the
     * query builder (not the Equipment Eloquent model) specifically so
     * the aliased `value`/`aggregate` result columns are read off plain
     * stdClass rows rather than through Eloquent's magic property
     * accessor, which only recognizes real model attributes.
     *
     * Both parameters are declared `literal-string`, not just `string`,
     * so this can only ever be called with a hardcoded column
     * expression/name baked in at each call site above — never with
     * unsanitized, request-derived input concatenated into raw SQL.
     *
     * Filters out soft-deleted rows explicitly: querying via DB::table()
     * rather than the Equipment model (see above) means Eloquent's
     * SoftDeletes global scope does NOT apply here the way it does to
     * every other Equipment::query() call in this controller.
     *
     * @param  literal-string  $selectRaw
     * @param  literal-string  $groupBy
     * @return array<int, array{value: string, count: int}>
     */
    private function countsByRawSelect(string $selectRaw, string $groupBy): array
    {
        return DB::table('equipment')
            ->whereNull('deleted_at')
            ->selectRaw($selectRaw)
            ->groupBy($groupBy)
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row) => ['value' => (string) $row->value, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Grouped {value, count} rows for a Tier 1 FK column (lookup-
     * normalization ADR, Step 2) — joins to the reference table so the
     * breakdown groups by (and displays) the row's `name`, not the raw
     * id. An inner join deliberately excludes equipment rows whose FK is
     * still NULL (pre-cutover records the backfill hasn't matched, or a
     * row created before this field was required) rather than lumping
     * them under a misleading "unknown" bucket — same semantics as the
     * raw-string version excluding NULLs implicitly via GROUP BY.
     *
     * Both parameters are `literal-string`, matching countsByRawSelect()'s
     * safety rationale above: only ever called with a hardcoded table/
     * column pair, never request-derived input.
     *
     * @param  literal-string  $refTable
     * @param  literal-string  $fkColumn
     * @return array<int, array{value: string, count: int}>
     */
    private function countsByReferenceJoin(string $refTable, string $fkColumn): array
    {
        return DB::table('equipment')
            ->join($refTable, "equipment.{$fkColumn}", '=', "{$refTable}.id")
            ->whereNull('equipment.deleted_at')
            ->selectRaw("{$refTable}.name as value, count(*) as aggregate")
            ->groupBy("{$refTable}.name")
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row) => ['value' => (string) $row->value, 'count' => (int) $row->aggregate])
            ->all();
    }
}

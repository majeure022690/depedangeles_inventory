<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AuditLogResource;
use App\Models\Equipment;
use App\Models\EquipmentTransaction;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspSpeedTest;
use App\Models\IspSubscriptionCost;
use App\Models\Personnel;
use App\Models\StakeholderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only browse of the append-only activity log. Nothing here ever
 * writes, edits or deletes a row, and no route for that exists.
 *
 * Filters are exact-match on whitelisted columns; `search` matches the
 * description (the dotted action name, e.g. `equipment.updated`) or the
 * actor's name/email, live or as snapshotted when the row was written.
 *
 * Actors below scope All see only their own actions and rows about
 * records within their reach; security events about others are hidden.
 */
class AuditLogController extends Controller
{
    use ResolvesListParameters;

    private const MAX_ACTOR_OPTIONS = 500;

    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::AuditLogView->value);

        $search = $request->string('search')->trim()->toString();

        $activities = $this->visibleActivities($request->user())
            ->with('causer')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('description', 'like', "%{$search}%")
                    ->orWhereIn('causer_id', User::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->select('id'))
                    // The snapshot is the only attribution left once the
                    // account is deleted, and keeps the name at the time.
                    ->orWhere('properties->actor_snapshot->name', 'like', "%{$search}%")
                    ->orWhere('properties->actor_snapshot->email', 'like', "%{$search}%"),
            ))
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->when($request->filled('subject_type'), fn ($query) => $query->where('subject_type', $request->string('subject_type')->toString()))
            ->when($request->filled('causer_id'), fn ($query) => $query->where('causer_id', $request->integer('causer_id')))
            ->when($request->date('date_from'), fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($request->date('date_to'), fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AuditLogResource::collection($activities)
            ->additional(['message' => 'Success'])
            ->response();
    }

    /**
     * Filter dropdowns: the distinct events present, and the distinct
     * subject types as `{value: FQCN, label: class basename}` (the
     * `subject_type` filter expects the FQCN back), and the actors for the
     * `causer_id` filter. All drawn from the rows the actor can see.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize(Permission::AuditLogView->value);

        $actor = $request->user();

        return response()->json([
            'data' => [
                'events' => $this->visibleActivities($actor)
                    ->whereNotNull('event')
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event')
                    ->values()
                    ->all(),
                'subject_types' => $this->visibleActivities($actor)
                    ->whereNotNull('subject_type')
                    ->distinct()
                    ->orderBy('subject_type')
                    ->pluck('subject_type')
                    ->map(fn (string $type) => ['value' => $type, 'label' => class_basename($type)])
                    ->values()
                    ->all(),
                'actors' => $this->actorOptions($actor),
            ],
            'message' => 'Success',
        ]);
    }

    /**
     * @return Builder<Activity>
     */
    private function visibleActivities(User $actor): Builder
    {
        $query = Activity::query();

        if ($actor->dataScope() === DataScope::All) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->where('causer_type', $actor->getMorphClass())
                ->where('causer_id', $actor->id))
            ->orWhere(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNull('event')
                    ->orWhere(fn (Builder $query) => $query
                        ->where('event', '!=', 'authorization.denied')
                        ->where('event', 'not like', 'auth.%')))
                ->where(fn (Builder $query) => $this->whereSubjectReachable($query, $actor))));
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function whereSubjectReachable(Builder $query, User $actor): void
    {
        $equipmentIds = Equipment::query()->visibleTo($actor)->select('equipment.id');
        $ispAccountIds = IspAccount::query()->visibleTo($actor)->select('isp_accounts.id');

        $subjects = [
            Personnel::class => Personnel::query()->visibleTo($actor)->select('personnel.id'),
            Equipment::class => $equipmentIds,
            EquipmentTransaction::class => EquipmentTransaction::query()->whereIn('equipment_id', $equipmentIds)->select('id'),
            IspAccount::class => $ispAccountIds,
            IspSpeedTest::class => IspSpeedTest::query()->whereIn('isp_account_id', $ispAccountIds)->select('id'),
            IspSubscriptionCost::class => IspSubscriptionCost::query()->whereIn('isp_account_id', $ispAccountIds)->select('id'),
            User::class => User::query()->visibleTo($actor)->select('users.id'),
        ];

        if ($actor->office_id !== null) {
            $subjects[StakeholderProfile::class] = StakeholderProfile::query()->where('office_id', $actor->office_id)->select('id');
            $subjects[InternetConnectivitySurvey::class] = InternetConnectivitySurvey::query()->where('office_id', $actor->office_id)->select('id');
        }

        foreach ($subjects as $type => $ids) {
            $query->orWhere(fn (Builder $query) => $query
                ->where('subject_type', $type)
                ->whereIn('subject_id', $ids));
        }
    }

    /**
     * Users that appear as causers, as `{value: causer_id, label: name}`
     * sorted by label. Deleted accounts are labelled from their latest
     * actor snapshot. Capped at the most recently active actors.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function actorOptions(User $actor): array
    {
        $causerIds = $this->visibleActivities($actor)
            ->where('causer_type', (new User)->getMorphClass())
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->orderByRaw('MAX(id) DESC')
            ->limit(self::MAX_ACTOR_OPTIONS)
            ->pluck('causer_id')
            ->map(fn ($id) => (int) $id);

        $labels = User::query()->whereIn('id', $causerIds)->pluck('name', 'id');

        $deletedIds = $causerIds->diff($labels->keys());

        if ($deletedIds->isNotEmpty()) {
            $snapshots = Activity::query()
                ->whereIn('id', $this->visibleActivities($actor)
                    ->selectRaw('MAX(id)')
                    ->where('causer_type', (new User)->getMorphClass())
                    ->whereIn('causer_id', $deletedIds)
                    ->whereNotNull('properties->actor_snapshot->name')
                    ->groupBy('causer_id'))
                ->get(['causer_id', 'properties'])
                ->mapWithKeys(fn (Activity $activity) => [(int) $activity->causer_id => $activity->properties['actor_snapshot']['name'] ?? null]);

            foreach ($deletedIds as $id) {
                $name = $snapshots->get($id);
                $labels->put($id, (is_string($name) && $name !== '' ? $name : "User #{$id}").' (deleted)');
            }
        }

        return $labels
            ->map(fn ($label, $id) => ['value' => (int) $id, 'label' => (string) $label])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}

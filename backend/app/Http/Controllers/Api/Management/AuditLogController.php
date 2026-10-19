<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Api\Management\Concerns\ResolvesListParameters;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AuditLogResource;
use App\Models\User;
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
 */
class AuditLogController extends Controller
{
    use ResolvesListParameters;

    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::AuditLogView->value);

        $search = $request->string('search')->trim()->toString();

        $activities = Activity::query()
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
     * `subject_type` filter expects the FQCN back).
     */
    public function options(): JsonResponse
    {
        $this->authorize(Permission::AuditLogView->value);

        return response()->json([
            'data' => [
                'events' => Activity::query()
                    ->whereNotNull('event')
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event')
                    ->values()
                    ->all(),
                'subject_types' => Activity::query()
                    ->whereNotNull('subject_type')
                    ->distinct()
                    ->orderBy('subject_type')
                    ->pluck('subject_type')
                    ->map(fn (string $type) => ['value' => $type, 'label' => class_basename($type)])
                    ->values()
                    ->all(),
            ],
            'message' => 'Success',
        ]);
    }
}

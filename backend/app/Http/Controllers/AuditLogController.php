<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit_log.view admin screen: a read-only, paginated browse of the
 * append-only `audit_logs` table (see AuditLog's own doc-comment — writes
 * happen exclusively via AuditLog::record(), scattered across nearly every
 * controller/policy in the app). ONE action — index() — on purpose: no
 * create/store/edit/update/destroy exist, and none ever should, for an
 * append-only log.
 *
 * Authorization mirrors ReferenceDataController::index()/UserController::
 * index(): there's no single model instance to check against (this is an
 * aggregate listing, not a per-record view), so the bare
 * `audit_log.view` permission string is authorized directly — no Policy
 * class exists for this resource (see Permission::AuditLogView's
 * doc-comment).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::AuditLogView->value);

        $search = $request->string('search')->toString() ?: null;
        $action = $request->string('action')->toString() ?: null;
        $subjectType = $request->string('subject_type')->toString() ?: null;
        $from = $request->date('from');
        $to = $request->date('to');

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($search, fn ($query) => $query->where(
                fn ($q) => $q->where('action', 'like', "%{$search}%")
                    ->orWhereHas('actor', fn ($actorQuery) => $actorQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    // Also match the actor_snapshot JSON — the only
                    // attribution left once actor_id nulls out via
                    // nullOnDelete (see AuditLog::record()'s doc-comment),
                    // and searchable regardless so a rename doesn't hide
                    // historical rows from a search for the name at the
                    // time of the action.
                    ->orWhere('properties->actor_snapshot->name', 'like', "%{$search}%")
                    ->orWhere('properties->actor_snapshot->email', 'like', "%{$search}%"),
            ))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($subjectType, fn ($query) => $query->where('subject_type', $subjectType))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'action' => $log->action,
                'actor' => $this->resolveActor($log),
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
            ]);

        return Inertia::render('audit-log/Index', [
            'logs' => $logs,
            'filterOptions' => [
                'actions' => AuditLog::query()
                    ->select('action')
                    ->distinct()
                    ->orderBy('action')
                    ->pluck('action')
                    ->all(),
                'subjectTypes' => AuditLog::query()
                    ->whereNotNull('subject_type')
                    ->select('subject_type')
                    ->distinct()
                    ->orderBy('subject_type')
                    ->pluck('subject_type')
                    ->map(fn (string $type) => [
                        'value' => $type,
                        'label' => class_basename($type),
                    ])
                    ->values()
                    ->all(),
            ],
            'filters' => [
                'search' => $search,
                'action' => $action,
                'subject_type' => $subjectType,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    /**
     * Prefer the live `actor()` relation (still-existing user account);
     * fall back to the `properties.actor_snapshot` captured at write time
     * (see AuditLog::record()'s doc-comment) when the actor account has
     * since been deleted; `null` for genuinely system/console-initiated
     * rows with no actor at all.
     *
     * @return array{id: int|null, name: string|null, email: string|null}|null
     */
    private function resolveActor(AuditLog $log): ?array
    {
        if ($log->actor) {
            return [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
            ];
        }

        $snapshot = $log->properties['actor_snapshot'] ?? null;

        if (! $snapshot) {
            return null;
        }

        return [
            'id' => null,
            'name' => $snapshot['name'] ?? null,
            'email' => $snapshot['email'] ?? null,
        ];
    }
}

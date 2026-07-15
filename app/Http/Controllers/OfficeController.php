<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OfficeStoreRequest;
use App\Http\Requests\OfficeUpdateRequest;
use App\Models\AuditLog;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OfficeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Office::class);

        $offices = Office::query()
            ->search($request->string('search')->toString() ?: null)
            ->orderBy('office_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Office $office) => [
                'id' => $office->id,
                'office_name' => $office->office_name,
                'office_type' => $office->office_type,
                'school_id' => $office->school_id,
                'district' => $office->district,
                'division' => $office->division,
                'region' => $office->region,
                'is_active' => $office->is_active,
            ]);

        return Inertia::render('offices/Index', [
            'offices' => $offices,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Office::class);

        return Inertia::render('offices/Create');
    }

    public function store(OfficeStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] ??= true;

        $office = Office::create($data);

        AuditLog::record('office.created', $office, ['attributes' => $data]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Office created.')]);

        return to_route('offices.edit', $office);
    }

    public function edit(Office $office): Response
    {
        $this->authorize('update', $office);

        return Inertia::render('offices/Edit', [
            'office' => $office->only([
                'id', 'office_name', 'office_type', 'school_id', 'address',
                'region', 'district', 'division', 'contact_number', 'email', 'is_active',
            ]),
        ]);
    }

    public function update(OfficeUpdateRequest $request, Office $office): RedirectResponse
    {
        $before = $office->getOriginal();

        $office->update($request->validated());

        $changes = collect($office->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('office.updated', $office, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Office updated.')]);

        return to_route('offices.edit', $office);
    }

    /**
     * Hard-delete, guarded the same two-layer way as
     * ReferenceDataController::destroy() (see its doc-comment): Office is
     * the FK backbone for users/stakeholder_profiles/
     * internet_connectivity_surveys, all three now `restrictOnDelete()`
     * (see 2026_07_15_110000_restrict_office_delete_on_dependent_tables),
     * so the delete is simply attempted and the resulting QueryException
     * caught here rather than pre-checked — the FK is already authoritative
     * and atomic, and a redundant pre-query wouldn't buy any additional
     * safety against the check-then-delete race.
     */
    public function destroy(Office $office): RedirectResponse
    {
        $this->authorize('delete', $office);

        try {
            DB::transaction(function () use ($office): void {
                $office->delete();

                AuditLog::record('office.deleted', $office, [
                    'office_name' => $office->office_name,
                    'office_type' => $office->office_type,
                    'school_id' => $office->school_id,
                ]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'delete' => __('This office is still in use and cannot be deleted — deactivate it instead.'),
                ]);
            }

            throw $e;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Office deleted.')]);

        return to_route('offices.index');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\GenerateRpcppeReportRequest;
use App\Models\Office;
use App\Models\Personnel;
use App\Models\User;
use App\Services\RpcppeReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The RPCPPE workbook for one office (IRIS equipment plus School Property).
 * Each download is audited as `report.generated`.
 */
class ReportController extends Controller
{
    /**
     * Offices the user's data scope reaches and supply officers; with
     * `?office_id=`, that office's personnel (school heads first) and those
     * accountable for equipment or school property.
     */
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermissionTo(Permission::ReportsRpcppe), 403);

        $data = [
            'offices' => Office::query()
                ->active()
                ->when($user->dataScope() !== DataScope::All, fn ($query) => $query->whereKey($user->office_id ?? 0))
                ->get(['id', 'office_name', 'school_id'])
                ->map(fn (Office $office) => ['value' => $office->id, 'label' => $office->school_id ? "{$office->office_name} ({$office->school_id})" : $office->office_name])
                ->all(),
            'supply_officers' => array_values(Personnel::query()->visibleTo($user)->active()->withPositionLike('Supply Officer')->with('personnelPosition:id,name')->orderBy('last_name')->get(['id', 'full_name', 'position_id'])->map(fn (Personnel $personnel) => $personnel->toOption())->all()),
        ];

        $officeId = $request->integer('office_id');

        if ($officeId > 0 && $user->reachesOffice($officeId)) {
            $data['people'] = array_values(Personnel::query()->visibleTo($user)->active()->where('office_id', $officeId)->schoolHeadsFirst()->with('personnelPosition:id,name')->get(['id', 'full_name', 'position_id'])->map(fn (Personnel $personnel) => $personnel->toOption())->all());
            $data['accountable_officers'] = Personnel::query()
                ->visibleTo($user)
                ->where('office_id', $officeId)
                ->where(fn ($query) => $query
                    ->whereHas('currentlyAccountableForEquipment')
                    ->orWhereIn('id', fn ($query) => $query->select('accountable_officer_id')->from('property_items')->whereNull('deleted_at')))
                ->with('personnelPosition:id,name')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'position_id'])
                ->map(fn (Personnel $personnel) => $personnel->toOption())
                ->all();
        }

        return response()->json(['data' => $data, 'message' => 'Success']);
    }

    public function rpcppe(GenerateRpcppeReportRequest $request, Office $office, RpcppeReport $report): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->reportInput();

        activity()
            ->performedOn($office)
            ->event('report.generated')
            ->withProperties([
                'report' => 'rpcppe',
                'school_year' => $input['school_year'],
                'accountable_officer_id' => $input['accountable_officer_id'],
                'prepared_by_id' => $input['prepared_by']?->id,
                'school_head_id' => $input['school_head']?->id,
                'supply_officer_id' => $input['supply_officer']?->id,
            ])
            ->log('report.generated');

        $label = Str::of($office->office_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->limit(60, '')->toString();

        return response()
            ->download($report->generate($office, $user, $input), "RPCPPE_{$label}_{$input['school_year']}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}

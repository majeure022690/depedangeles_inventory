<?php

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\EmailMessageResource;
use App\Models\EmailMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Settings > Email log (ported from rwatch, same `{data, meta, message}`
 * shape). Rows are written by App\Listeners\RecordEmailMessage.
 */
class EmailLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::EmailLogManage->value);

        $page = EmailMessage::query()->latest('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => EmailMessageResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'message' => 'Success',
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->authorize(Permission::EmailLogManage->value);

        $count = EmailMessage::query()->delete();

        Log::channel('security')->info('Email log cleared', [
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
            'entries' => $count,
        ]);

        activity()
            ->event('email_log.cleared')
            ->withProperties(['entries' => $count, 'ip' => $request->ip()])
            ->log('email_log.cleared');

        return response()->json(['message' => 'Email log cleared.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Services\NotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationCenter $notifications): JsonResponse
    {
        return response()->json(['data' => $notifications->for($request->user()), 'message' => 'Success']);
    }
}

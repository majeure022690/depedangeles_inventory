<?php

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Controller;
use App\Services\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecuritySettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SecuritySettings::all(),
            'message' => 'Success',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'setting_key' => ['required', 'string'],
            'value' => ['required', 'integer'],
        ]);

        $setting = SecuritySettings::update($validated['setting_key'], (int) $validated['value']);

        return response()->json([
            'data' => $setting,
            'message' => 'Security setting updated successfully.',
        ]);
    }
}

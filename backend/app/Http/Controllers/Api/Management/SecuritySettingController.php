<?php

namespace App\Http\Controllers\Api\Management;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Route `permission:` middleware is only the fast fail; each action
 * re-checks security_settings.manage itself.
 *
 * Audit: every effective change is one `security_setting.updated` row
 * with the key's old and new value.
 */
class SecuritySettingController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::SecuritySettingsManage->value);

        return response()->json([
            'data' => SecuritySettings::all(),
            'message' => 'Success',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SecuritySettingsManage->value);

        $validated = $request->validate([
            'setting_key' => ['required', 'string'],
            'value' => ['required', 'integer'],
        ]);

        $key = (string) $validated['setting_key'];
        $old = collect(SecuritySettings::all())->firstWhere('key', $key)['value'] ?? null;

        $setting = SecuritySettings::update($key, (int) $validated['value']);

        if ($old !== $setting['value']) {
            $row = SystemSetting::query()->where('key', $key)->firstOrFail();

            activity()
                ->causedBy($request->user())
                ->performedOn($row)
                ->event('security_setting.updated')
                ->withChanges([
                    'old' => [$key => $old],
                    'attributes' => [$key => $setting['value']],
                ])
                ->withProperties(['setting_key' => $key])
                ->log('security_setting.updated');
        }

        return response()->json([
            'data' => $setting,
            'message' => 'Security setting updated successfully.',
        ]);
    }
}

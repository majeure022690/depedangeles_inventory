<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\TrustedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrustedDeviceController extends Controller
{
    public function __construct(private readonly TrustedDeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $this->devices->current($user, $request)?->id;

        return response()->json([
            'data' => $user->trustedDevices()
                ->where('expires_at', '>', now())
                ->latest('last_used_at')
                ->get()
                ->map(fn (TrustedDevice $device) => [
                    'id' => $device->id,
                    'label' => $device->label(),
                    'ip_address' => $device->last_used_ip ?? $device->ip_address,
                    'trusted_at' => $device->created_at,
                    'last_used_at' => $device->last_used_at,
                    'expires_at' => $device->expires_at,
                    'is_current' => $device->id === $currentId,
                ])
                ->values(),
            'meta' => ['trust_device_days' => $this->devices->days($user)],
            'message' => 'Success',
        ]);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        $user = $request->user();
        $trusted = $user->trustedDevices()->whereKey($device)->firstOrFail();
        $wasCurrent = $this->devices->current($user, $request)?->id === $trusted->id;

        $trusted->delete();

        Log::channel('security')->info('Trusted device revoked', ['user_id' => $user->id, 'device_id' => $device, 'ip' => $request->ip()]);

        $response = response()->json(['message' => 'Success']);

        return $wasCurrent ? $response->withCookie($this->devices->forgetCookie()) : $response;
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->trustedDevices()->delete();

        Log::channel('security')->info('All trusted devices revoked', ['user_id' => $user->id, 'count' => $count, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Success'])->withCookie($this->devices->forgetCookie());
    }
}

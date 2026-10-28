<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpaController extends Controller
{
    private const CSP = [
        "default-src 'self'",
        "script-src 'self' 'nonce-%s' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://static.cloudflareinsights.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: blob: https://*.tile.openstreetmap.org",
        "connect-src 'self' https://www.google.com/recaptcha/ https://cloudflareinsights.com",
        'frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/',
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        'upgrade-insecure-requests',
    ];

    public function __invoke(Request $request): Response
    {
        $index = (string) config('app.spa_index');

        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
        abort_unless(is_file($index), 404);

        // Cloudflare copies this nonce onto the bot-detection script it injects.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(SecurityHeaders::SPA_DOCUMENT, true);

        return response((string) file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => sprintf(implode('; ', self::CSP), $nonce),
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);
    }

    public function equipmentLabel(Request $request, string $id): Response|RedirectResponse
    {
        if (is_file((string) config('app.spa_index'))) {
            return $this->__invoke($request);
        }

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/equipment/{$id}/edit");
    }
}

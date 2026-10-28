<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    private const STRICT_CSP = "default-src 'self'; img-src 'self' data: blob:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; worker-src 'self' blob:; frame-ancestors 'none'";

    private const WEAK_CSP = "default-src * 'unsafe-inline' 'unsafe-eval'";

    public function test_an_api_response_setting_its_own_csp_still_gets_the_strict_csp(): void
    {
        Route::middleware('api')->get('/api/csp-probe', fn () => response()->json([], 200, ['Content-Security-Policy' => self::WEAK_CSP]));

        $this->assertSame(self::STRICT_CSP, $this->getJson('/api/csp-probe')->headers->get('Content-Security-Policy'));
    }

    public function test_a_non_api_download_setting_its_own_csp_still_gets_the_strict_csp(): void
    {
        Route::get('/csp-probe-download', fn () => response('file', 200, [
            'Content-Type' => 'text/html',
            'Content-Security-Policy' => self::WEAK_CSP,
        ]));

        $this->assertSame(self::STRICT_CSP, $this->get('/csp-probe-download')->headers->get('Content-Security-Policy'));
    }

    public function test_the_qr_label_redirect_without_a_built_spa_gets_the_strict_csp(): void
    {
        config(['app.spa_index' => '/nonexistent/index.html', 'app.frontend_url' => 'https://dictis.example.gov.ph']);

        $response = $this->get('/equipment/12/edit');

        $response->assertRedirect();
        $this->assertSame(self::STRICT_CSP, $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_spa_document_keeps_its_nonce_csp(): void
    {
        $index = (string) tempnam(sys_get_temp_dir(), 'spa');
        file_put_contents($index, '<!doctype html><title>DICTIS</title>');
        config(['app.spa_index' => $index]);

        try {
            $csp = (string) $this->get('/dashboard')->headers->get('Content-Security-Policy');
        } finally {
            @unlink($index);
        }

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{24}'/", $csp);
        $this->assertNotSame(self::STRICT_CSP, $csp);
    }
}

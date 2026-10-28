<?php

namespace Tests\Feature\Security;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SpaDocumentTest extends TestCase
{
    private const HTML = "<!doctype html>\n<html lang=\"en\"><head><title>DICTIS</title><script type=\"module\" src=\"/assets/index-abc.js\"></script></head><body><div id=\"root\"></div></body></html>\n";

    private const EXPECTED_CSP = "default-src 'self'; script-src 'self' 'nonce-%s' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https://*.tile.openstreetmap.org; connect-src 'self' https://www.google.com/recaptcha/ https://cloudflareinsights.com; frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests";

    private const API_CSP = "default-src 'self'; img-src 'self' data: blob:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; worker-src 'self' blob:; frame-ancestors 'none'";

    private string $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = (string) tempnam(sys_get_temp_dir(), 'spa');
        file_put_contents($this->index, self::HTML);
        config(['app.spa_index' => $this->index, 'app.frontend_url' => 'https://dictis.example.gov.ph']);
    }

    protected function tearDown(): void
    {
        @unlink($this->index);

        parent::tearDown();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function nonceOf(TestResponse $response): string
    {
        if (preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $match) !== 1) {
            $this->fail('The CSP carries no nonce.');
        }

        return $match[1];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentPaths(): array
    {
        return [
            'root' => ['/'],
            'login' => ['/login'],
            'nested page' => ['/dashboard/equipment'],
            'printed QR label' => ['/equipment/12/edit'],
            'non-numeric label id' => ['/equipment/not-a-number/edit'],
            'index.html by name' => ['/index.html'],
        ];
    }

    #[DataProvider('documentPaths')]
    public function test_every_spa_path_serves_the_exact_built_document_with_a_nonce_csp(string $path): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $this->assertSame(self::HTML, $response->getContent());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertSame(sprintf(self::EXPECTED_CSP, $this->nonceOf($response)), $response->headers->get('Content-Security-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_the_nonce_is_16_random_bytes_and_fresh_on_every_response(): void
    {
        $nonces = [];

        for ($i = 0; $i < 20; $i++) {
            $nonce = $this->nonceOf($this->get('/dashboard'));
            $decoded = base64_decode($nonce, true);

            $this->assertNotFalse($decoded);
            $this->assertSame(16, strlen($decoded));
            $nonces[] = $nonce;
        }

        $this->assertCount(20, array_unique($nonces));
    }

    public function test_script_execution_is_never_loosened(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $directives = collect(explode(';', $csp))->map(fn (string $d) => trim($d));
        $scriptSrc = (string) $directives->first(fn (string $d) => str_starts_with($d, 'script-src '));

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString("'strict-dynamic'", $csp);
        $this->assertNotContains('*', explode(' ', $scriptSrc));
        $this->assertNotContains('https:', explode(' ', $scriptSrc));
        $this->assertContains("object-src 'none'", $directives);
        $this->assertContains("base-uri 'self'", $directives);
        $this->assertContains("frame-ancestors 'none'", $directives);
    }

    public function test_the_nonce_appears_only_in_the_csp_header(): void
    {
        $response = $this->get('/login?x=1');
        $nonce = $this->nonceOf($response);

        $this->assertStringNotContainsString($nonce, (string) $response->getContent());
        $this->assertEmpty($response->headers->getCookies());

        foreach ($response->headers->all() as $name => $values) {
            if ($name !== 'content-security-policy') {
                $this->assertStringNotContainsString($nonce, implode(' ', array_map('strval', $values)), "{$name} carries the nonce");
            }
        }
    }

    public function test_request_data_never_reaches_the_document(): void
    {
        $response = $this->get('/%3Cscript%3Ealert(1)%3C%2Fscript%3E?q=%3Cimg%20src%3Dx%3E', ['X-Injected' => '<svg onload=alert(1)>']);

        $response->assertOk();
        $this->assertSame(self::HTML, $response->getContent());
    }

    public function test_the_document_is_never_cached_or_revalidated_into_a_304(): void
    {
        $first = $this->get('/');

        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertFalse($first->headers->has('ETag'));
        $this->assertFalse($first->headers->has('Last-Modified'));

        $this->get('/', ['If-None-Match' => '"anything"', 'If-Modified-Since' => now()->addDay()->toRfc7231String()])
            ->assertOk()
            ->assertContent(self::HTML);
    }

    public function test_api_responses_keep_the_strict_api_csp_without_a_nonce(): void
    {
        $this->assertSame(self::API_CSP, $this->getJson('/api/user')->headers->get('Content-Security-Policy'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDocumentPaths(): array
    {
        return [
            'unknown api route' => ['/api/does-not-exist'],
            'api root' => ['/api'],
            'unknown sanctum route' => ['/sanctum/nope'],
            'health check suffix' => ['/up/extra'],
        ];
    }

    #[DataProvider('nonDocumentPaths')]
    public function test_api_sanctum_and_health_paths_never_fall_back_to_the_document(string $path): void
    {
        $response = $this->getJson($path);

        $response->assertNotFound();
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
        $this->assertSame(self::API_CSP, $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_health_check_still_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_writes_to_spa_paths_are_not_served_the_document(): void
    {
        $response = $this->post('/dashboard');

        $response->assertNotFound();
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
    }

    public function test_without_a_built_spa_the_label_redirects_and_other_paths_are_404(): void
    {
        config(['app.spa_index' => $this->index.'.missing']);

        $this->get('/equipment/12/edit')->assertRedirect('https://dictis.example.gov.ph/equipment/12/edit');
        $this->get('/')->assertNotFound();
        $this->get('/dashboard')->assertNotFound();
    }

    public function test_a_stray_index_html_in_public_still_gets_the_nonce_response(): void
    {
        $stray = public_path('index.html');
        $this->assertFileDoesNotExist($stray, 'backend/public/index.html would be served by the web server without the nonce CSP');

        file_put_contents($stray, 'stale');

        try {
            $response = $this->get('/index.html');

            $response->assertOk();
            $this->assertSame(self::HTML, $response->getContent());
            $this->nonceOf($response);
        } finally {
            @unlink($stray);
        }
    }

    public function test_the_web_server_never_serves_index_html_itself(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule ^index\.html$ index.php [L]', $htaccess);
        $this->assertLessThan(
            strpos($htaccess, 'RewriteRule ^ index.php [L]'),
            strpos($htaccess, 'RewriteRule ^index\.html$ index.php [L]'),
        );
        $this->assertStringNotContainsString('<Files "index.html">', $htaccess);
        $this->assertMatchesRegularExpression('/^DirectoryIndex index\.php$/m', $htaccess);

        $deploy = (string) file_get_contents(base_path('../deploy/plesk.sh'));

        $this->assertStringNotContainsString('cp -R dist/.', $deploy);
        $this->assertStringContainsString('rm -f "$ROOT/backend/public/index.html"', $deploy);
        $this->assertStringContainsString('"$ROOT/backend/storage/app/spa/index.html"', $deploy);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyHeadersTest extends TestCase
{
    private const SPOOFED = [
        'X-Forwarded-For' => '203.0.113.7',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '8443',
        'X-Forwarded-Prefix' => '/evil',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-probe', fn (Request $request) => [
            'url' => route('equipment.edit', 5),
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    }

    private function trustProxy(?string $headers = null): void
    {
        config([
            'app.trusted_proxies' => '127.0.0.1',
            'app.trusted_proxy_headers' => $headers ?? 'for',
        ]);

        $provider = $this->app->getProvider(AppServiceProvider::class);
        $this->assertInstanceOf(AppServiceProvider::class, $provider);
        $provider->configureTrustedProxies();
    }

    private function realUrl(string $scheme): string
    {
        $authority = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return "{$scheme}://{$authority}".($port ? ":{$port}" : '').'/equipment/5/edit';
    }

    public function test_trusted_proxy_by_default_honours_only_x_forwarded_for(): void
    {
        $this->trustProxy();

        $this->withHeaders(self::SPOOFED)->getJson('/_proxy-probe')
            ->assertOk()
            ->assertJson([
                'url' => $this->realUrl('http'),
                'ip' => '203.0.113.7',
                'secure' => false,
            ]);
    }

    public function test_opted_in_headers_are_honoured(): void
    {
        $this->trustProxy('for, proto');

        $this->withHeaders(self::SPOOFED)->getJson('/_proxy-probe')
            ->assertOk()
            ->assertJson([
                'url' => $this->realUrl('https'),
                'ip' => '203.0.113.7',
                'secure' => true,
            ]);
    }

    public function test_forwarded_headers_are_ignored_without_a_trusted_proxy(): void
    {
        $this->withHeaders(self::SPOOFED)->getJson('/_proxy-probe')
            ->assertOk()
            ->assertJson([
                'url' => $this->realUrl('http'),
                'ip' => '127.0.0.1',
                'secure' => false,
            ]);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function headerLists(): array
    {
        return [
            'default' => ['for', Request::HEADER_X_FORWARDED_FOR],
            'proto and port opt-in' => ['for,proto,port', Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT],
            'unknown names are dropped' => ['for,forwarded,aws_elb', Request::HEADER_X_FORWARDED_FOR],
            'empty falls back to for, never all' => ['', Request::HEADER_X_FORWARDED_FOR],
        ];
    }

    #[DataProvider('headerLists')]
    public function test_header_list_parsing(string $names, int $expected): void
    {
        $this->assertSame($expected, AppServiceProvider::trustedProxyHeaders($names));
    }
}

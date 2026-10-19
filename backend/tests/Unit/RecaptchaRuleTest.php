<?php

namespace Tests\Unit;

use App\Rules\Recaptcha;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecaptchaRuleTest extends TestCase
{
    private function failures(string $action = 'admin_login'): array
    {
        $messages = [];
        (new Recaptcha($action))->validate('recaptcha_token', 'token', function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        return $messages;
    }

    private function fakeGoogle(array $body): void
    {
        config(['services.recaptcha.secret_key' => 'real-secret', 'app.frontend_url' => 'https://dictis.example.ph']);
        Http::fake(['www.google.com/*' => Http::response($body)]);
    }

    public function test_passes_when_google_reports_success(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->assertSame([], $this->failures());
    }

    public function test_fails_when_google_reports_failure(): void
    {
        config(['services.recaptcha.secret_key' => 'test-secret']);
        Http::fake(['*' => Http::response(['success' => false])]);

        $this->assertNotEmpty($this->failures());
    }

    public function test_skips_verification_when_secret_key_is_not_configured(): void
    {
        config(['services.recaptcha.secret_key' => null]);
        Http::fake();

        $this->assertSame([], $this->failures());
        Http::assertNothingSent();
    }

    public function test_fails_closed_when_secret_key_is_not_configured_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['services.recaptcha.secret_key' => null]);
        Http::fake();

        $this->assertNotEmpty($this->failures());
        Http::assertNothingSent();
    }

    public function test_a_human_token_for_this_form_and_site_passes(): void
    {
        $this->app['env'] = 'production';
        $this->fakeGoogle(['success' => true, 'score' => 0.9, 'action' => 'admin_login', 'hostname' => 'dictis.example.ph']);

        $this->assertSame([], $this->failures());
    }

    public function test_a_token_solved_for_another_form_is_rejected(): void
    {
        $this->fakeGoogle(['success' => true, 'score' => 0.9, 'action' => 'reset_password', 'hostname' => 'dictis.example.ph']);

        $this->assertNotEmpty($this->failures('admin_login'));
    }

    public function test_a_token_from_another_site_is_rejected_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->fakeGoogle(['success' => true, 'score' => 0.9, 'action' => 'admin_login', 'hostname' => 'evil.example.com']);

        $this->assertNotEmpty($this->failures());
    }

    public function test_a_low_score_is_rejected(): void
    {
        $this->fakeGoogle(['success' => true, 'score' => 0.2, 'action' => 'admin_login']);

        $this->assertNotEmpty($this->failures());
    }

    public function test_production_refuses_googles_test_key(): void
    {
        $this->app['env'] = 'production';
        config(['services.recaptcha.secret_key' => Recaptcha::TEST_SECRET_KEY]);
        Http::fake();

        $this->assertNotEmpty($this->failures());
        Http::assertNothingSent();
    }

    public function test_an_unreachable_google_fails_closed(): void
    {
        config(['services.recaptcha.secret_key' => 'real-secret']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertNotEmpty($this->failures());
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_branded_reset_email_without_changing_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertTrue(Hash::check('CorrectHorse123!', $user->fresh()->password));
    }

    public function test_forgot_password_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If that account exists, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_reset_email_links_to_frontend_and_carries_division_branding(): void
    {
        config(['app.frontend_url' => 'https://dictis.test']);
        $user = User::factory()->create(['name' => 'Juan Dela Cruz', 'email' => 'staff@example.com']);

        $html = (new ResetPasswordNotification('test-token'))->toMail($user)->render();

        $this->assertStringContainsString('https://dictis.test/portal/reset-password?token=test-token&amp;email=staff%40example.com', $html);
        $this->assertStringContainsString('Schools Division Office', $html);
        $this->assertStringContainsString('Hello Juan Dela Cruz', $html);
    }

    public function test_reset_email_is_delivered_with_inline_seal(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->create();

        $user->sendPasswordResetNotification('test-token');

        $sent = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Reset your password', $sent->getSubject());
        $this->assertCount(1, $sent->getAttachments());
        $this->assertStringContainsString('cid:', $sent->getHtmlBody());
        $this->assertStringContainsString('test-token', $sent->getTextBody());
    }

    public function test_password_can_be_reset_with_a_valid_token_and_sessions_are_revoked(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        DB::table('sessions')->insert([
            'id' => 'stale-session', 'user_id' => $user->id, 'ip_address' => null,
            'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewHorse123!',
            'password_confirmation' => 'BrandNewHorse123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNewHorse123!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-session']);
    }

    public function test_reset_rejects_an_invalid_token_and_a_weak_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectHorse123!')]);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'BrandNewHorse123!',
            'password_confirmation' => 'BrandNewHorse123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('CorrectHorse123!', $user->fresh()->password));
    }
}

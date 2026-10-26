<?php

namespace Tests\Feature\Management;

use App\Enums\Permission;
use App\Models\EmailMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EmailLogTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['mail.default' => 'array']);
    }

    private function userWithOnly(Permission $permission): User
    {
        $role = Role::create(['name' => 'only-'.$permission->value, 'label' => 'Only '.$permission->label()]);
        $role->givePermissionTo($permission->value);

        $user = User::factory()->create();
        $user->assignRole($role->name);

        return $user;
    }

    public function test_password_reset_emails_are_recorded_as_sent_with_encrypted_recipients(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $user->sendPasswordResetNotification('test-token');

        $message = EmailMessage::query()->sole();
        $this->assertSame('staff@example.test', $message->recipients);
        $this->assertSame('Reset your password', $message->subject);
        $this->assertSame('Password reset', $message->type);
        $this->assertSame(EmailMessage::STATUS_SENT, $message->status);
        $this->assertStringNotContainsString('staff@example.test', (string) DB::table('email_messages')->value('recipients'));
    }

    public function test_admin_can_list_the_email_log_with_only_allowlisted_fields(): void
    {
        EmailMessage::query()->create(['recipients' => 'a@example.test', 'subject' => 'Hello', 'type' => 'Password reset', 'status' => EmailMessage::STATUS_SENT, 'provider_message_id' => 're_123']);
        EmailMessage::query()->create(['recipients' => 'b@example.test', 'subject' => 'Newer', 'type' => null, 'status' => EmailMessage::STATUS_SENDING]);

        $admin = $this->userWithOnly(Permission::EmailLogManage);

        $response = $this->actingAs($admin)->getJson('/api/settings/email-log?page=1')
            ->assertOk()
            ->assertJsonPath('message', 'Success')
            ->assertJsonPath('data.0.recipients', 'b@example.test')
            ->assertJsonPath('data.1.provider_message_id', 're_123')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 2);

        $this->assertSame(['data', 'meta', 'message'], array_keys($response->json()));
        $this->assertSame(
            ['id', 'recipients', 'subject', 'type', 'status', 'provider_message_id', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_clearing_the_email_log_deletes_every_row_and_is_audited(): void
    {
        EmailMessage::query()->create(['recipients' => 'a@example.test', 'subject' => 'Hello', 'type' => null, 'status' => EmailMessage::STATUS_SENT]);
        $admin = $this->userWithOnly(Permission::EmailLogManage);

        $this->actingAs($admin)->deleteJson('/api/settings/email-log', ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Email log cleared.');

        $this->assertSame(0, EmailMessage::query()->count());
        $activity = Activity::query()->where('event', 'email_log.cleared')->sole();
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(1, $activity->properties['entries']);
    }

    public function test_clearing_the_email_log_requires_the_current_password(): void
    {
        EmailMessage::query()->create(['recipients' => 'a@example.test', 'subject' => 'Hello', 'type' => null, 'status' => EmailMessage::STATUS_SENT]);
        $admin = $this->userWithOnly(Permission::EmailLogManage);
        $auditRows = Activity::query()->count();

        $this->actingAs($admin)->deleteJson('/api/settings/email-log')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)->deleteJson('/api/settings/email-log', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame($auditRows, Activity::query()->count());

        $this->actingAs($admin)->deleteJson('/api/settings/email-log', ['current_password' => 'password'])->assertOk();
        $this->assertSame(0, EmailMessage::query()->count());
    }

    public function test_email_log_needs_its_own_permission(): void
    {
        EmailMessage::query()->create(['recipients' => 'a@example.test', 'subject' => 'Hello', 'type' => null, 'status' => EmailMessage::STATUS_SENT]);
        $settingsOnly = $this->userWithOnly(Permission::SecuritySettingsManage);
        $everythingElse = $this->userWithAllPermissions([Permission::EmailLogManage]);

        foreach ([$settingsOnly, $everythingElse] as $user) {
            $this->actingAs($user)->getJson('/api/settings/email-log')->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/settings/email-log', ['current_password' => 'password'])->assertForbidden();
        }

        $this->assertSame(1, EmailMessage::query()->count());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/settings/email-log')->assertUnauthorized();
        $this->deleteJson('/api/settings/email-log')->assertUnauthorized();
    }
}

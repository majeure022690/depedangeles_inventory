<?php

namespace Tests\Feature\Settings;

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * POST /api/profile replaces the old settings profile/password/avatar
 * routes (plan §2c).
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'OldPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), ...$attributes])
            ->givePermissionTo(Permission::EquipmentView->value);
    }

    public function test_name_can_be_changed_without_the_current_password(): void
    {
        $user = $this->user(['name' => 'Old Name']);

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => 'New Name',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_email_change_requires_the_correct_current_password(): void
    {
        $user = $this->user(['email' => 'old@example.com']);

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'wrong-password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => self::PASSWORD,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_new_password_must_meet_the_password_policy(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => self::PASSWORD,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_email_must_be_unique(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $other->email,
            'current_password' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_avatar_can_be_uploaded_and_replaced(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('first.png', 200, 200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn (string $url) => str_contains($url, '/storage/avatars/'));

        $first = $user->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('/^avatars\/[0-9a-f-]{36}\.png$/', $first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->post('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }

    public function test_a_rejected_replacement_avatar_keeps_the_old_one(): void
    {
        $user = $this->user(['avatar_path' => 'avatars/old.png']);
        Storage::disk('public')->put('avatars/old.png', 'image');

        // Passes the request rules, then fails FileUploadService's dimension check.
        $this->actingAs($user)->post('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('tiny.png', 20, 20),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('avatar');

        Storage::disk('public')->assertExists('avatars/old.png');
        $this->assertSame('avatars/old.png', $user->fresh()->avatar_path);
    }

    public function test_avatar_can_be_removed(): void
    {
        $user = $this->user(['avatar_path' => 'avatars/old.png']);
        Storage::disk('public')->put('avatars/old.png', 'image');

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'remove_avatar' => true,
        ])->assertOk()->assertJsonPath('data.avatar_url', null);

        Storage::disk('public')->assertMissing('avatars/old.png');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => null]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function maliciousFiles(): array
    {
        return [
            'php script' => ['shell.php', '<?php system($_GET["c"]); ?>', 'application/x-php'],
            'php disguised as jpg' => ['shell.jpg', '<?php system($_GET["c"]); ?>', 'image/jpeg'],
            'html page' => ['page.html', '<script>alert(1)</script>', 'text/html'],
            'svg with script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            'double extension' => ['photo.jpg.php', '<?php echo 1; ?>', 'application/x-php'],
        ];
    }

    #[DataProvider('maliciousFiles')]
    public function test_avatar_rejects_scripts_and_fake_images(string $name, string $content, string $mime): void
    {
        $user = $this->user();
        $file = UploadedFile::fake()->createWithContent($name, $content)->mimeType($mime);

        $this->actingAs($user)->post('/api/profile', ['name' => $user->name, 'email' => $user->email, 'avatar' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_is_re_encoded_so_hidden_payloads_are_dropped(): void
    {
        $user = $this->user();
        $image = imagecreatetruecolor(400, 400);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $file = UploadedFile::fake()->createWithContent('photo.jpg', $jpeg.'<?php system($_GET["c"]); ?><script>alert(1)</script>');

        $this->actingAs($user)->post('/api/profile', ['name' => $user->name, 'email' => $user->email, 'avatar' => $file], ['Accept' => 'application/json'])
            ->assertOk();

        $stored = Storage::disk('public')->get($user->fresh()->avatar_path);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_profile_changes_are_audited_without_secrets_and_keep_the_actor_snapshot(): void
    {
        $user = $this->user(['name' => 'Old Name', 'email' => 'me@example.com']);

        $this->actingAs($user)->postJson('/api/profile', [
            'name' => 'New Name',
            'email' => 'me@example.com',
            'current_password' => self::PASSWORD,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $log = Activity::where('event', 'updated')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->sole();

        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame(['name' => 'New Name'], $log->attribute_changes['attributes']);
        $this->assertSame(['name' => 'New Name', 'email' => 'me@example.com'], $log->properties['actor_snapshot']);

        // The snapshot outlives the account; causer_id has no FK.
        $user->delete();
        $this->assertSame('me@example.com', $log->fresh()->properties['actor_snapshot']['email']);
    }

    public function test_guests_cannot_update_a_profile(): void
    {
        $this->postJson('/api/profile', ['name' => 'X', 'email' => 'x@example.com'])->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentAttachment;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

class EquipmentAttachmentTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    private const string PDF_BODY = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        Storage::fake('local');

        $holder = Personnel::factory()->create();
        $this->equipment = Equipment::factory()->create([
            'current_accountable_officer_id' => $holder->id,
            'current_end_user_id' => null,
        ]);
    }

    private function editor(DataScope $scope = DataScope::All): User
    {
        return $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentEdit], scope: $scope);
    }

    private function pdf(string $name = 'par-scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF_BODY);
    }

    private function url(?Equipment $equipment = null, ?EquipmentAttachment $attachment = null): string
    {
        $base = '/api/equipment/'.($equipment ?? $this->equipment)->id.'/attachments';

        return $attachment ? $base.'/'.$attachment->id : $base;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upload(User $user, array $overrides = [], ?Equipment $equipment = null): TestResponse
    {
        return $this->actingAs($user)->post($this->url($equipment), array_merge([
            'file' => $this->pdf(),
            'document_type' => EquipmentAttachment::PMP_DOCUMENT_TYPE,
            'document_no' => 'PAR-2026-0001',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function storedAttachment(?Equipment $equipment = null): EquipmentAttachment
    {
        $equipment ??= $this->equipment;
        $path = "equipment-attachments/{$equipment->id}/".fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, self::PDF_BODY);

        return $equipment->attachments()->create([
            'document_type' => EquipmentAttachment::PMP_DOCUMENT_TYPE,
            'document_no' => 'ICS-77',
            'original_filename' => 'ics scan.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => strlen(self::PDF_BODY),
            'file_hash' => hash('sha256', self::PDF_BODY),
        ]);
    }

    private function outOfScopeUser(): User
    {
        return $this->editor(DataScope::Own);
    }

    public function test_pdf_upload_is_stored_on_the_private_disk_and_returns_the_resource(): void
    {
        $user = $this->editor();

        $response = $this->upload($user)->assertCreated()
            ->assertJsonPath('message', 'File attached.')
            ->assertJsonPath('data.document_type', EquipmentAttachment::PMP_DOCUMENT_TYPE)
            ->assertJsonPath('data.document_no', 'PAR-2026-0001')
            ->assertJsonPath('data.original_filename', 'par-scan.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.file_size', strlen(self::PDF_BODY))
            ->assertJsonPath('data.uploaded_by', $user->name);

        $this->assertEqualsCanonicalizing(
            ['id', 'document_type', 'document_no', 'original_filename', 'mime_type', 'file_size', 'uploaded_by', 'created_at'],
            array_keys($response->json('data')),
        );

        $attachment = EquipmentAttachment::findOrFail((int) $response->json('data.id'));
        $this->assertSame('local', $attachment->disk);
        $this->assertMatchesRegularExpression(
            '#^equipment-attachments/'.$this->equipment->id.'/[0-9a-f-]{36}\.pdf$#',
            $attachment->path,
        );
        $this->assertSame($user->id, $attachment->uploaded_by_user_id);
        $this->assertSame(hash('sha256', self::PDF_BODY), $attachment->file_hash);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(self::PDF_BODY, Storage::disk('local')->get($attachment->path));

        $activity = Activity::query()->where('event', 'equipment.attachment.uploaded')->sole();
        $this->assertSame($this->equipment->id, (int) $activity->subject_id);
        $this->assertSame($this->equipment->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->id, (int) $activity->causer_id);
        $this->assertSame([
            'attachment_id' => $attachment->id,
            'document_type' => EquipmentAttachment::PMP_DOCUMENT_TYPE,
            'document_no' => 'PAR-2026-0001',
            'original_filename' => 'par-scan.pdf',
            'file_hash' => $attachment->file_hash,
        ], $activity->properties->except('actor_snapshot')->all());
    }

    public function test_image_upload_is_re_encoded_and_stored_with_its_real_mime(): void
    {
        $user = $this->editor();

        $response = $this->upload($user, [
            'file' => UploadedFile::fake()->image('delivery.jpg', 120, 80),
            'document_no' => null,
        ])->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.document_no', null)
            ->assertJsonPath('data.original_filename', 'delivery.jpg');

        $attachment = EquipmentAttachment::findOrFail((int) $response->json('data.id'));
        $this->assertMatchesRegularExpression('#^equipment-attachments/'.$this->equipment->id.'/[0-9a-f-]{36}\.jpg$#', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($attachment->path)), $attachment->file_hash);
        $this->assertSame(Storage::disk('local')->size($attachment->path), $attachment->file_size);
    }

    public function test_image_re_encoding_strips_trailing_payload(): void
    {
        if (! function_exists('imagecreatefrompng') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD with PNG support is not available.');
        }

        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $marker = '<?php echo "polyglot-marker"; ?>';

        $response = $this->upload($this->editor(), [
            'file' => UploadedFile::fake()->createWithContent('photo.png', $png.$marker),
        ])->assertCreated()->assertJsonPath('data.mime_type', 'image/png');

        $stored = Storage::disk('local')->get(EquipmentAttachment::findOrFail((int) $response->json('data.id'))->path);
        $this->assertStringStartsWith("\x89PNG", (string) $stored);
        $this->assertStringNotContainsString('polyglot-marker', (string) $stored);
    }

    public function test_upload_requires_a_file_and_a_document_type(): void
    {
        $this->actingAs($this->editor())->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'document_type'])
            ->assertJsonMissingValidationErrors(['document_no']);

        $this->assertDatabaseCount('equipment_attachments', 0);
    }

    public function test_upload_rejects_a_disallowed_extension(): void
    {
        $this->upload($this->editor(), ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Only PDF, JPG, PNG and WEBP files are accepted.']);

        $this->upload($this->editor(), ['file' => UploadedFile::fake()->create('macro.docx', 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('equipment_attachments', 0);
    }

    public function test_upload_rejects_files_over_five_megabytes(): void
    {
        $this->upload($this->editor(), ['file' => UploadedFile::fake()->create('big.pdf', EquipmentAttachment::MAX_KILOBYTES + 1, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file must be 5 MB or smaller.']);

        $this->assertDatabaseCount('equipment_attachments', 0);
    }

    public function test_upload_rejects_an_unknown_document_type_and_an_overlong_document_no(): void
    {
        $this->upload($this->editor(), ['document_type' => 'Love Letter', 'document_no' => str_repeat('x', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_type', 'document_no']);

        $this->upload($this->editor(), ['document_no' => str_repeat('x', 255)])->assertCreated();
    }

    public function test_fake_pdf_with_wrong_magic_bytes_is_rejected(): void
    {
        $this->upload($this->editor(), ['file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The uploaded file is not a genuine PDF.']);

        $this->assertDatabaseCount('equipment_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_image_renamed_to_pdf_is_rejected_by_its_real_mime(): void
    {
        $source = UploadedFile::fake()->image('x.png', 60, 60);
        $png = (string) file_get_contents($source->getRealPath());

        $this->upload($this->editor(), ['file' => UploadedFile::fake()->createWithContent('scan.pdf', $png)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The uploaded file is not a genuine PDF.']);

        $this->assertDatabaseCount('equipment_attachments', 0);
    }

    public function test_pdf_renamed_to_an_image_is_rejected(): void
    {
        $this->upload($this->editor(), ['file' => UploadedFile::fake()->createWithContent('photo.jpg', self::PDF_BODY)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('equipment_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_download_streams_the_file_with_safe_headers_for_a_viewer(): void
    {
        $attachment = $this->storedAttachment();
        $viewer = $this->userWithPermissions([Permission::EquipmentView]);

        $response = $this->actingAs($viewer)->get($this->url(attachment: $attachment))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('ics scan.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(self::PDF_BODY, $response->streamedContent());
    }

    public function test_download_is_404_when_the_stored_file_is_missing(): void
    {
        $attachment = $this->storedAttachment();
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($this->editor())->getJson($this->url(attachment: $attachment))->assertNotFound();
    }

    public function test_delete_removes_the_row_and_file_and_audits(): void
    {
        $attachment = $this->storedAttachment();
        $user = $this->editor();

        $this->actingAs($user)->deleteJson($this->url(attachment: $attachment))->assertNoContent();

        $this->assertDatabaseMissing('equipment_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);

        $activity = Activity::query()->where('event', 'equipment.attachment.deleted')->sole();
        $this->assertSame($this->equipment->id, (int) $activity->subject_id);
        $this->assertSame($user->id, (int) $activity->causer_id);
        $this->assertSame($attachment->id, $activity->properties['attachment_id']);
        $this->assertSame($attachment->file_hash, $activity->properties['file_hash']);
        $this->assertSame('ics scan.pdf', $activity->properties['original_filename']);
    }

    public function test_upload_and_delete_require_equipment_edit(): void
    {
        $viewer = $this->userWithPermissions([Permission::EquipmentView]);
        $attachment = $this->storedAttachment();

        $this->upload($viewer)->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->url(attachment: $attachment))->assertForbidden();

        $this->assertDatabaseCount('equipment_attachments', 1);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame(0, Activity::query()->where('event', 'like', 'equipment.attachment.%')->count());
    }

    public function test_download_requires_equipment_view(): void
    {
        $attachment = $this->storedAttachment();
        $editorOnly = $this->userWithPermissions([Permission::EquipmentEdit]);

        $this->actingAs($editorOnly)->getJson($this->url(attachment: $attachment))->assertForbidden();
    }

    public function test_out_of_scope_equipment_is_not_found_for_download_and_delete(): void
    {
        $attachment = $this->storedAttachment();
        $outsider = $this->outOfScopeUser();

        $this->actingAs($outsider)->getJson($this->url(attachment: $attachment))->assertNotFound();
        $this->actingAs($outsider)->deleteJson($this->url(attachment: $attachment))->assertNotFound();

        $this->assertDatabaseHas('equipment_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_out_of_scope_equipment_is_not_found_for_upload(): void
    {
        $this->upload($this->outOfScopeUser())->assertNotFound();

        $this->assertDatabaseCount('equipment_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachment_of_another_equipment_is_not_found(): void
    {
        $other = Equipment::factory()->create(['current_accountable_officer_id' => null, 'current_end_user_id' => null]);
        $foreign = $this->storedAttachment($other);
        $user = $this->editor();

        $this->actingAs($user)->getJson($this->url(attachment: $foreign))->assertNotFound();
        $this->actingAs($user)->deleteJson($this->url(attachment: $foreign))->assertNotFound();

        $this->assertDatabaseHas('equipment_attachments', ['id' => $foreign->id, 'equipment_id' => $other->id]);
        Storage::disk('local')->assertExists($foreign->path);
    }

    public function test_soft_deleted_equipment_keeps_its_attachments_but_the_routes_404(): void
    {
        $attachment = $this->storedAttachment();
        $user = $this->editor();

        $this->equipment->delete();

        $this->assertDatabaseHas('equipment_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);

        $this->upload($user)->assertNotFound();
        $this->actingAs($user)->getJson($this->url(attachment: $attachment))->assertNotFound();
        $this->actingAs($user)->deleteJson($this->url(attachment: $attachment))->assertNotFound();

        $this->assertDatabaseHas('equipment_attachments', ['id' => $attachment->id]);
    }

    public function test_upload_limiter_allows_thirty_requests_a_minute_per_user(): void
    {
        $user = $this->editor();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->postJson($this->url(), [])->assertUnprocessable();
        }

        $this->actingAs($user)->postJson($this->url(), [])->assertTooManyRequests();

        $this->actingAs($this->editor())->postJson($this->url(), [])->assertUnprocessable();
    }
}

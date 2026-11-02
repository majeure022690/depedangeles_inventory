<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\EquipmentTransactionDocument;
use App\Models\Personnel;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\Concerns\RecordsTransactions;
use Tests\TestCase;

class EquipmentTransactionDocumentTest extends TestCase
{
    use CreatesUsersWithRoles, RecordsTransactions, RefreshDatabase;

    private const string PDF_BODY = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $user;

    private Personnel $officer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->user = $this->userWithPermissions([Permission::EquipmentView, Permission::EquipmentTransactionsCreate]);
        $this->officer = Personnel::factory()->create();
    }

    private function item(): Equipment
    {
        return Equipment::factory()->create(['current_accountable_officer_id' => $this->officer->id, 'current_end_user_id' => null, 'disposition_status' => 'Normal']);
    }

    private function upload(?User $user = null): int
    {
        return $this->actingAs($user ?? $this->user)
            ->post('/api/equipment/transaction-documents', ['file' => UploadedFile::fake()->createWithContent('par.pdf', self::PDF_BODY)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_an_uploaded_file_is_claimed_by_the_transaction_and_downloadable(): void
    {
        $equipment = $this->item();
        $uploadId = $this->upload();

        $response = $this->actingAs($this->user)->postJson("/api/equipment/{$equipment->id}/transactions", $this->transactionPayload([
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->officer->id,
            'documents' => [['document_type' => 'Property Acknowledgment Receipt (PAR)', 'document_no' => 'PAR-9', 'upload_id' => $uploadId]],
        ]))->assertCreated();

        $document = EquipmentTransactionDocument::findOrFail($uploadId);
        $this->assertSame($response->json('data.id'), $document->equipment_transaction_id);
        $this->assertSame([['id' => $uploadId, 'document_type' => 'Property Acknowledgment Receipt (PAR)', 'document_no' => 'PAR-9', 'original_filename' => 'par.pdf', 'has_file' => true]], $response->json('data.documents'));

        $this->actingAs($this->user)->get("/api/equipment/{$equipment->id}/transactions/{$document->equipment_transaction_id}/documents/{$uploadId}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_disposal_marks_the_item_and_its_peripherals_disposed(): void
    {
        $desktop = $this->item();
        $monitor = Equipment::factory()->create(['parent_equipment_id' => $desktop->id, 'current_accountable_officer_id' => $this->officer->id, 'current_end_user_id' => null, 'disposition_status' => 'Normal']);
        $desktop->forceFill(['awaiting_receipt_since' => now()])->saveQuietly();

        $this->actingAs($this->user)->postJson("/api/equipment/{$desktop->id}/transactions", $this->transactionPayload([
            'transaction_type' => 'Disposal',
            'documents' => [$this->supportingDocument($this->user, 'Waste Material Report (WMR)')],
        ]))->assertCreated();

        $this->assertSame('Disposed', $desktop->fresh()->disposition_status);
        $this->assertNull($desktop->fresh()->awaiting_receipt_since);
        $this->assertSame('Disposed', $monitor->fresh()->disposition_status);
    }

    public function test_required_fields_follow_the_transaction_type(): void
    {
        $equipment = $this->item();
        $url = "/api/equipment/{$equipment->id}/transactions";

        $this->actingAs($this->user)->postJson($url, ['transaction_type' => 'Issuance/Transfer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accountable_officer_id', 'date_assigned_accountable_officer', 'received_by_id', 'date_received_new_accountable', 'documents']);

        $this->actingAs($this->user)->postJson($url, ['transaction_type' => 'Beginning Inventory', 'accountable_officer_id' => $this->officer->id, 'date_assigned_accountable_officer' => '2026-11-02'])
            ->assertCreated();

        $this->actingAs($this->user)->postJson($url, ['transaction_type' => 'Return'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['received_by_id', 'date_received_new_accountable', 'documents'])
            ->assertJsonMissingValidationErrors(['accountable_officer_id']);

        $this->actingAs($this->user)->postJson($url, $this->transactionPayload(['transaction_type' => 'Disposal']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.documents.0', 'A disposal needs a Waste Material Report (WMR) among its documents.');

        $this->actingAs($this->user)->postJson($url, $this->transactionPayload(['transaction_type' => 'Disposal', 'documents' => [$this->supportingDocument($this->user, 'Waste Material Report (WMR)')]]))
            ->assertCreated();
    }

    public function test_someone_elses_or_an_already_used_upload_is_refused(): void
    {
        $equipment = $this->item();
        $foreign = $this->upload($this->userWithPermissions([Permission::EquipmentTransactionsCreate]));

        $this->actingAs($this->user)->postJson("/api/equipment/{$equipment->id}/transactions", $this->transactionPayload([
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->officer->id,
            'documents' => [['document_type' => 'Property Acknowledgment Receipt (PAR)', 'upload_id' => $foreign]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['documents.0.upload_id']);

        $mine = $this->upload();
        $payload = fn () => $this->transactionPayload([
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->officer->id,
            'documents' => [['document_type' => 'Property Acknowledgment Receipt (PAR)', 'upload_id' => $mine]],
        ]);
        $this->actingAs($this->user)->postJson("/api/equipment/{$equipment->id}/transactions", $payload())->assertCreated();
        $this->actingAs($this->user)->postJson("/api/equipment/{$equipment->id}/transactions", $payload())->assertUnprocessable()->assertJsonValidationErrors(['documents.0.upload_id']);
    }

    public function test_a_bulk_transfer_shares_one_upload_across_every_item(): void
    {
        $items = [$this->item(), $this->item()];
        $uploadId = $this->upload();

        $this->actingAs($this->user)->postJson('/api/equipment/transactions/bulk', $this->transactionPayload([
            'equipment_ids' => array_map(fn (Equipment $item) => $item->id, $items),
            'transaction_type' => 'Issuance/Transfer',
            'accountable_officer_id' => $this->officer->id,
            'documents' => [['document_type' => 'Property Acknowledgment Receipt (PAR)', 'upload_id' => $uploadId]],
        ]))->assertCreated();

        $documents = EquipmentTransactionDocument::query()->whereNotNull('equipment_transaction_id')->get();
        $this->assertCount(2, $documents);
        $this->assertCount(1, $documents->pluck('path')->unique());
    }

    public function test_uploads_need_the_permission_and_a_real_document(): void
    {
        $this->actingAs($this->userWithPermissions([Permission::EquipmentView]))
            ->post('/api/equipment/transaction-documents', ['file' => UploadedFile::fake()->createWithContent('par.pdf', self::PDF_BODY)], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post('/api/equipment/transaction-documents', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_unclaimed_uploads_are_pruned_after_a_day(): void
    {
        $stale = EquipmentTransactionDocument::findOrFail($this->upload());
        $stale->forceFill(['created_at' => now()->subDays(2)])->save();
        $fresh = $this->upload();

        $this->artisan('equipment:prune-pending-documents')->assertSuccessful();

        $this->assertModelMissing($stale);
        Storage::disk('local')->assertMissing($stale->path);
        $this->assertNotNull(EquipmentTransactionDocument::find($fresh));
    }
}

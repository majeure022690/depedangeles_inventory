<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegenerateEquipmentQrCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_poisoned_rows_without_changing_them(): void
    {
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => 'http://evil.example/equipment/1/edit']);

        $this->artisan('equipment:regenerate-qr-codes')
            ->expectsOutputToContain('http://evil.example/equipment/1/edit')
            ->assertSuccessful();

        $this->assertSame('http://evil.example/equipment/1/edit', $equipment->fresh()?->qr_code);
    }

    public function test_force_rewrites_poisoned_and_empty_rows_and_audits_each(): void
    {
        config(['app.url' => 'https://dictis.example.gov.ph']);
        $poisoned = Equipment::factory()->create();
        $empty = Equipment::factory()->create();
        $correct = Equipment::factory()->create();
        DB::table('equipment')->where('id', $poisoned->id)->update(['qr_code' => 'http://evil.example/x']);
        DB::table('equipment')->where('id', $empty->id)->update(['qr_code' => null]);
        $poisoned->delete();

        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])->assertSuccessful();

        $this->assertSame("https://dictis.example.gov.ph/equipment/{$poisoned->id}/edit", Equipment::withTrashed()->find($poisoned->id)?->qr_code);
        $this->assertSame("https://dictis.example.gov.ph/equipment/{$empty->id}/edit", $empty->fresh()?->qr_code);
        $this->assertSame("https://dictis.example.gov.ph/equipment/{$correct->id}/edit", $correct->fresh()?->qr_code);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Equipment::class,
            'subject_id' => $poisoned->id,
            'event' => 'equipment.qr_code.regenerated',
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $correct->id,
            'event' => 'equipment.qr_code.regenerated',
        ]);
    }

    public function test_dry_run_lists_soft_deleted_rows_and_writes_no_audit(): void
    {
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => 'http://evil.example/trashed']);
        $equipment->delete();

        $this->artisan('equipment:regenerate-qr-codes')
            ->expectsOutputToContain('http://evil.example/trashed')
            ->expectsOutputToContain('Re-run with --force')
            ->assertSuccessful();

        $this->assertSame('http://evil.example/trashed', Equipment::withTrashed()->find($equipment->id)?->qr_code);
        $this->assertDatabaseMissing('activity_log', ['event' => 'equipment.qr_code.regenerated']);
    }

    public function test_force_on_empty_rows_only_fills_without_reprint_warning(): void
    {
        $first = Equipment::factory()->create();
        $second = Equipment::factory()->create();
        DB::table('equipment')->where('id', $first->id)->update(['qr_code' => null]);
        DB::table('equipment')->where('id', $second->id)->update(['qr_code' => '']);

        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])
            ->expectsOutput('Filled 2 missing qr_code value(s).')
            ->doesntExpectOutputToContain('Rewrote')
            ->doesntExpectOutputToContain('Reprint')
            ->assertSuccessful();
    }

    public function test_force_on_mixed_rows_reports_filled_and_reprint_counts_separately(): void
    {
        $empty = Equipment::factory()->create();
        $first = Equipment::factory()->create();
        $second = Equipment::factory()->create();
        DB::table('equipment')->where('id', $empty->id)->update(['qr_code' => null]);
        DB::table('equipment')->where('id', $first->id)->update(['qr_code' => 'http://old.example/1']);
        DB::table('equipment')->where('id', $second->id)->update(['qr_code' => 'http://old.example/2']);

        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])
            ->expectsOutput('Filled 1 missing qr_code value(s).')
            ->expectsOutputToContain('Rewrote 2 qr_code value(s) that pointed at a different URL. Reprint those 2 label(s)')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('activity_log')->where('event', 'equipment.qr_code.regenerated')->count());
    }

    public function test_dry_run_summary_separates_missing_from_different_urls(): void
    {
        $empty = Equipment::factory()->create();
        $other = Equipment::factory()->create();
        DB::table('equipment')->where('id', $empty->id)->update(['qr_code' => null]);
        DB::table('equipment')->where('id', $other->id)->update(['qr_code' => 'http://old.example/x']);

        $this->artisan('equipment:regenerate-qr-codes')
            ->expectsOutputToContain('1 qr_code value(s) missing, 1 pointing at a different URL. Re-run with --force')
            ->assertSuccessful();
    }

    public function test_force_is_idempotent(): void
    {
        config(['app.url' => 'https://dictis.example.gov.ph']);
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => 'http://evil.example/x']);

        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])
            ->expectsOutputToContain('Rewrote 1 qr_code value(s) that pointed at a different URL. Reprint those 1 label(s)')
            ->assertSuccessful();
        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])
            ->expectsOutputToContain('Every qr_code already points at')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('activity_log')->where('event', 'equipment.qr_code.regenerated')->count());
        $this->assertSame("https://dictis.example.gov.ph/equipment/{$equipment->id}/edit", $equipment->fresh()?->qr_code);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function appUrls(): array
    {
        return [
            'trailing slash' => ['https://dictis.example.gov.ph/', 'https://dictis.example.gov.ph'],
            'sub-path' => ['https://deped.example.gov.ph/dictis', 'https://deped.example.gov.ph/dictis'],
            'sub-path with trailing slash' => ['https://deped.example.gov.ph/dictis/', 'https://deped.example.gov.ph/dictis'],
        ];
    }

    public function test_qr_code_url_ignores_the_request_host_and_base_path(): void
    {
        config(['app.url' => 'https://deped.example.gov.ph/dictis/']);
        $equipment = Equipment::factory()->create();

        url()->setRequest(Request::create('http://evil.example/legacy/api/equipment', 'POST', server: [
            'SCRIPT_NAME' => '/legacy/index.php',
            'SCRIPT_FILENAME' => base_path('public/index.php'),
        ]));

        $this->assertSame("https://deped.example.gov.ph/dictis/equipment/{$equipment->id}/edit", $equipment->qrCodeUrl());
    }

    #[DataProvider('appUrls')]
    public function test_force_builds_urls_from_app_url_shape_and_then_settles(string $appUrl, string $base): void
    {
        config(['app.url' => $appUrl]);
        $equipment = Equipment::factory()->create();
        DB::table('equipment')->where('id', $equipment->id)->update(['qr_code' => null]);

        $this->artisan('equipment:regenerate-qr-codes', ['--force' => true])->assertSuccessful();

        $this->assertSame("{$base}/equipment/{$equipment->id}/edit", $equipment->fresh()?->qr_code);

        $this->artisan('equipment:regenerate-qr-codes')
            ->expectsOutputToContain('Every qr_code already points at')
            ->assertSuccessful();
    }
}

<?php

namespace Tests\Feature;

use App\Models\IspAccount;
use App\Models\IspProvider;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the `restrictOnDelete()` constraint added to
 * `isp_accounts.isp_provider_id` by the lookup-normalization ADR's Tier 1 FK
 * migration (2026_07_11_010202_add_tier1_fk_column_to_isp_accounts_table.php)
 * — mirrors ItemTypeReferentialIntegrityTest for the Equipment/ItemType
 * pair: a reference-data row that's still referenced by an IspAccount
 * record must never be deletable, at the database level, regardless of
 * what app-layer code does or doesn't check. Retiring a reference value is
 * `is_active = false`, never a delete — see that migration's doc-comment.
 */
class IspProviderReferentialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_isp_provider_referenced_by_isp_account_throws_a_query_exception(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $ispProvider = IspProvider::where('name', 'PLDT')->firstOrFail();

        IspAccount::factory()->create([
            'isp_provider_id' => $ispProvider->id,
        ]);

        $this->expectException(QueryException::class);

        $ispProvider->delete();
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = current state of a physical asset (NOT the source
     * workbook's repeated-row-per-transaction pattern). Lifecycle history
     * lives in equipment_transactions.
     *
     * SCHEMA DECISION — lookup-backed columns are plain VARCHAR values
     * (matching lookups.value for the relevant App\Enums\LookupType),
     * not foreignId columns pointing at lookups.id. Rationale:
     *   1. Matches the source Excel data shape directly (plain text
     *      categorical values), minimizing transform complexity for
     *      Integration's future CSV/XLSX import pipeline.
     *   2. ~15 lookup-backed columns per table would mean ~15 extra FK
     *      columns/indexes and joins on every equipment list query, for
     *      values that are effectively a validated, admin-editable
     *      vocabulary rather than entities with their own identity.
     *   3. The explicitly requested query patterns (filter equipment by
     *      condition/category/disposition) are simple indexed-varchar
     *      filters this way, no join required.
     *   4. Renaming a lookup value is a rare admin action; if it becomes
     *      common, a backfill (UPDATE ... WHERE column = old SET = new)
     *      is cheap. Referential integrity for these columns is enforced
     *      at the application layer (validation against `lookups` by
     *      type), consistent with `lookups` being reference/vocabulary
     *      data rather than a parent entity.
     * The two genuine relationships to another entity — accountable
     * officer and end user — ARE real foreignId columns to `personnel`.
     */
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();

            $table->string('property_no')->unique();
            $table->string('old_property_no')->nullable();
            $table->string('serial_number')->nullable();

            $table->string('item'); // lookup type: item_type
            $table->string('uom'); // lookup type: unit_of_measure
            $table->string('brand_manufacturer'); // lookup type: brand
            $table->string('model')->nullable();
            $table->text('specifications')->nullable();

            $table->boolean('non_dcp')->default(false);
            $table->string('dcp_package')->nullable(); // lookup type: dcp_package
            $table->year('dcp_year')->nullable();

            $table->string('category'); // lookup type: category (High-Value >= 50k / Low-Value)
            $table->string('classification'); // lookup type: classification

            $table->string('gl_sl_code')->nullable();
            $table->string('uacs')->nullable();

            // Government asset register: acquisition cost is a required
            // accounting figure for COA reporting, so NOT NULL. Source
            // sheet stores it as currency-formatted text (e.g.
            // "P8,890.00") — Integration's importer is responsible for
            // stripping the currency formatting into a clean decimal.
            // Non-negative enforced at the application layer — Laravel 11+
            // dropped unsignedDecimal() as MySQL itself deprecated the
            // UNSIGNED attribute on DECIMAL columns.
            $table->decimal('acquisition_cost', 12, 2);
            $table->date('received_date')->nullable();
            $table->unsignedSmallInteger('estimated_useful_life')->nullable();

            $table->string('mode_acquisition'); // lookup type: mode_of_acquisition
            $table->string('source_acquisition'); // lookup type: source_of_acquisition
            $table->string('donor')->nullable(); // only relevant when mode_acquisition = Donation
            $table->string('source_fund'); // lookup type: source_of_funds
            $table->string('allotment_class'); // lookup type: allotment_class

            $table->string('pm_plan')->nullable();
            $table->text('equipment_location')->nullable();

            $table->boolean('non_functional')->default(false);
            $table->string('equipment_condition'); // lookup type: condition
            $table->string('disposition_status'); // lookup type: disposition_status

            $table->text('remarks')->nullable();
            // Nullable now; will be auto-derived from property_no once QR
            // generation is built. Unique because it's a 1:1 derivation
            // of property_no once populated (MySQL unique allows
            // multiple NULLs, so this doesn't block un-generated rows).
            $table->string('qr_code')->nullable()->unique();

            $table->boolean('under_warranty')->default(false);
            $table->date('end_warranty_date')->nullable();
            $table->string('supplier')->nullable();

            $table->foreignId('current_accountable_officer_id')
                ->nullable()
                ->constrained('personnel')
                ->nullOnDelete();
            $table->foreignId('current_end_user_id')
                ->nullable()
                ->constrained('personnel')
                ->nullOnDelete();

            $table->timestamps();
            // Genuine business need: equipment records (esp. disposed/
            // stolen/lost assets) must be retrievable for audit/COA
            // history even after removal from active inventory views.
            $table->softDeletes();

            $table->index('equipment_condition');
            $table->index('category');
            $table->index('disposition_status');
            // Common combined filter: e.g. "High-Value assets that are
            // For Repair" for a COA/disposal report.
            $table->index(['category', 'equipment_condition']);
            $table->index('serial_number');
            $table->index('item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};

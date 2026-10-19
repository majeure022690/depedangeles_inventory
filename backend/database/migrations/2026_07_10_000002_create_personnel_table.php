<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table name is explicitly `personnel` (singular) — Laravel's
     * pluralizer would otherwise produce `personnels`. The Personnel
     * model must declare `protected $table = 'personnel';` to match.
     *
     * Lookup-backed columns (ro_division, division_unit, position,
     * separation_cause, suffix) are stored as plain VARCHAR values that
     * must match `lookups.value` for the corresponding
     * App\Enums\LookupType, NOT as foreign keys to lookups.id. See the
     * migration-level note in create_equipment_table for the full
     * rationale (kept once there to avoid repeating it on every table).
     */
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();

            $table->string('employee_id')->unique();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix', 20)->nullable();

            // Mirrors the source workbook's auto-generated computed_field:
            // "LAST, FIRST MIDDLE - EMPLOYEEID" (suffix deliberately
            // excluded — the source format never included it). Stored
            // (not virtual) so it can be indexed for name search/sort
            // without recomputing on every read.
            $table->string('full_name', 500)->storedAs(
                "concat(`last_name`, ', ', `first_name`, ".
                "if(`middle_name` is null or `middle_name` = '', '', concat(' ', `middle_name`)), ".
                "' - ', `employee_id`)"
            );

            // lookup type: ro_offices
            $table->string('ro_division')->nullable();
            // lookup type: sdo_offices
            $table->string('division_unit')->nullable();
            // lookup type: position (~1,000 distinct position names)
            $table->string('position')->nullable();

            $table->boolean('oic')->default(false);
            $table->string('oic_office')->nullable();

            $table->string('mobile_1', 20)->nullable();
            $table->string('mobile_2', 20)->nullable();
            $table->string('deped_email')->nullable();
            $table->string('personal_email')->nullable();

            $table->date('date_hired')->nullable();

            $table->boolean('non_deped_funded')->default(false);

            // Multi-select in the source sheet. Stored as a native JSON
            // array of lookup values (type: teachers_funding_source)
            // rather than a comma-separated string (avoids delimiter
            // collisions / manual parsing) or a pivot table (overkill for
            // a single multi-select attribute with no extra metadata).
            $table->json('fund_source')->nullable();

            $table->boolean('inactive')->default(false);
            $table->date('separation_date')->nullable();
            // lookup type: cause_of_separation
            $table->string('separation_cause')->nullable();

            $table->string('transferred_from')->nullable();
            $table->string('transferred_to')->nullable();

            $table->timestamps();
            // Genuine business need to retain personnel history (past
            // accountable officers/end users referenced by
            // equipment_transactions must remain resolvable).
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('full_name');
            $table->index('position');
            $table->index('inactive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};

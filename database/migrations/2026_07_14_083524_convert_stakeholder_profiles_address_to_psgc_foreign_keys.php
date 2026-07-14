<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces `province`/`city_municipality`/`barangay` free-text columns
     * with real FKs into the new psgc_provinces/psgc_municipalities/
     * psgc_barangays tables, enabling cascading dropdowns instead of
     * plain text inputs. `psgc` is dropped outright — it's now derivable
     * from the selected barangay's own `code`, never stored separately.
     * `legislative_district` and `street` stay free text (no PSGC data
     * covers either). Nullable, matching every other column here
     * (progressively-filled record, no "required at insert" set).
     *
     * `complete_address` (a MySQL STORED generated column) is dropped
     * outright rather than rewritten to reference the new FKs — MySQL
     * generated columns can't reference other tables via JOIN, so this
     * moves to a PHP accessor on the model instead (StakeholderProfile::
     * completeAddress()), which also permanently resolves the earlier
     * firstOrCreate()-doesn't-refetch-generated-columns gotcha.
     *
     * Existing rows only ever held test/placeholder values (no real
     * production data yet), so this migration deletes rather than
     * attempts to map free-text values to PSGC codes.
     */
    public function up(): void
    {
        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropColumn(['complete_address', 'province', 'city_municipality', 'barangay', 'psgc']);
        });

        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('school_id')->constrained('psgc_provinces')->nullOnDelete();
            $table->foreignId('municipality_id')->nullable()->after('province_id')->constrained('psgc_municipalities')->nullOnDelete();
            $table->foreignId('barangay_id')->nullable()->after('legislative_district')->constrained('psgc_barangays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
            $table->dropConstrainedForeignId('municipality_id');
            $table->dropConstrainedForeignId('barangay_id');
        });

        Schema::table('stakeholder_profiles', function (Blueprint $table) {
            $table->string('province')->nullable()->after('school_id');
            $table->string('city_municipality')->nullable()->after('province');
            $table->string('barangay')->nullable()->after('legislative_district');
            $table->string('psgc')->nullable()->after('street');
            $table->string('complete_address', 500)->storedAs(
                "concat_ws(', ', `street`, `barangay`, `city_municipality`, `province`)"
            )->after('psgc');
        });
    }
};

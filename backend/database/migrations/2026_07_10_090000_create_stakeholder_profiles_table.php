<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SINGLETON TABLE — exactly one row should ever exist (one Division
     * Office stakeholder profile for this whole application, mirroring the
     * source workbook's single "Stakeholder Profile" sheet). Unlike every
     * other table in this app, this is not a list/CRUD resource: Backend
     * must use a `firstOrCreate([])`-style pattern in the controller, never
     * an index/paginated listing. No factory is provided for this reason —
     * seeded/fake multiples would misrepresent the intended shape of the
     * data.
     *
     * Because the one row is meant to be created empty and filled in
     * progressively through an edit form, every column here is nullable
     * (or has a safe boolean default) except the generated column and
     * timestamps — there is no "required at insert" set the way there is
     * for list resources like Equipment/Personnel.
     *
     * No soft deletes: a singleton settings-style record has no history of
     * distinct rows to preserve — there's only ever the one, and deleting
     * it (if that's ever a real operation) is a full reset, not something
     * an audit trail needs to reconstruct.
     *
     * No indexes beyond the primary key: with exactly one row, there is no
     * filter/sort/join query pattern to optimize for — see database
     * agent's decision rule "index for actual query patterns."
     *
     * Lookup-backed columns are plain VARCHAR values matching
     * `lookups.value` for the corresponding App\Enums\LookupType, NOT
     * foreign keys to lookups.id — see the migration-level note in
     * create_equipment_table for the full rationale. Multi-select fields
     * are native JSON arrays of lookup values, same treatment as
     * Personnel.fund_source.
     */
    public function up(): void
    {
        Schema::create('stakeholder_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('governance_level')->nullable(); // lookup type: governance_level
            $table->string('ro')->nullable(); // Region designation, e.g. "III" — single-tenant to one region, plain string
            $table->string('sdo')->nullable(); // e.g. "Angeles City"

            // Blank for a Division Office record; only meaningful if this
            // singleton pattern is ever reused at school level.
            $table->string('school_district')->nullable();
            $table->string('school_name')->nullable();
            $table->string('school_id')->nullable();

            $table->string('province')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('legislative_district')->nullable();
            $table->string('barangay')->nullable();
            $table->string('street')->nullable();
            $table->string('psgc')->nullable(); // Philippine Standard Geographic Code

            // Auto-generated from street/barangay/city_municipality/province,
            // same precedent as Personnel.full_name (STORED generated
            // column, not a PHP accessor). CONCAT_WS skips NULL/blank
            // components automatically, so a partially-filled address still
            // renders cleanly without manual conditional concatenation.
            $table->string('complete_address', 500)->storedAs(
                "concat_ws(', ', `street`, `barangay`, `city_municipality`, `province`)"
            );

            $table->text('notes_corrections')->nullable();
            $table->text('notes_recent_development')->nullable();

            $table->string('mobile_1', 20)->nullable();
            $table->string('mobile_2', 20)->nullable();
            $table->string('landline', 20)->nullable();

            // "RO/SDO Chief/School Head" — source is user-typed freeform
            // text (e.g. "DOMINGO, EDGARD C - 4341529"), NOT a validated FK
            // to personnel. Kept as plain strings deliberately.
            $table->string('chief_name')->nullable();
            $table->string('chief_position')->nullable();
            $table->string('chief_email')->nullable();
            $table->string('chief_mobile', 20)->nullable();

            // "Administrative Staff / Inventory Clerk" — same freeform-text
            // treatment as chief_*.
            $table->string('admin_staff_name')->nullable();
            $table->string('admin_staff_position')->nullable();
            $table->string('admin_staff_email')->nullable();
            $table->string('admin_staff_mobile', 20)->nullable();

            $table->string('network_administrator_name')->nullable();

            // GPS coordinates. decimal(10,7)/decimal(9,7) comfortably cover
            // the full valid longitude (-180..180) / latitude (-90..90)
            // ranges at ~1cm precision, well beyond the source data's
            // 5-decimal-place values (e.g. 120.59360 / 15.13570).
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('latitude', 9, 7)->nullable();

            // Multi-select checkboxes in the source sheet against a fixed
            // list. Stored as JSON arrays of lookup values, reusing
            // existing LookupType lists per the task instructions.
            $table->json('nearby_institutions')->nullable(); // lookup type: nearby_institution
            $table->string('nearby_institutions_other')->nullable();

            $table->unsignedInteger('travel_time_to_center_minutes')->nullable(); // parsed from source's "5 MINS" etc.

            $table->json('access_paths')->nullable(); // lookup type: type_of_access_road

            $table->json('transportation_options')->nullable(); // lookup type: by_transportation
            $table->string('transportation_other')->nullable();

            $table->boolean('transportation_difficult')->nullable()->default(false);
            $table->boolean('considered_very_remote')->nullable()->default(false);
            $table->text('remote_context_notes')->nullable();

            $table->boolean('gidca')->nullable()->default(false); // Geographically Isolated, Disadvantaged, and Conflict-Affected Areas
            $table->boolean('lms')->nullable()->default(false); // Last Mile School

            $table->json('community_engagement')->nullable(); // lookup type: community_engagement
            $table->text('community_context_remarks')->nullable();

            $table->dateTime('submitted_at')->nullable();
            $table->string('transaction_type')->nullable(); // lookup type: transaction_type

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stakeholder_profiles');
    }
};

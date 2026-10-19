<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: not every account holder is tied to a specific
            // school/office (e.g. admin). nullOnDelete so
            // deactivating/removing an office never cascades into deleting
            // the user accounts that reference it.
            $table->foreignId('office_id')->nullable()->after('email')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_id');
        });
    }
};

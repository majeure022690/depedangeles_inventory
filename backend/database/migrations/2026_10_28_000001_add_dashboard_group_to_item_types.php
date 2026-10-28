<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GROUPS = [
        'desktop_computer' => ['Desktop (package)', 'CPU', 'Monitor', 'Keyboard', 'Mouse'],
        'laptop' => ['Laptop'],
        'tablet' => ['Tablet', '2-in-1 Tablet'],
        'printer' => ['Printer', 'Multifunction Printer'],
        'tv' => ['Standard TV', 'Smart TV'],
        'projector_smartboard' => ['Projector', 'Interactive Whiteboards / Smartboards'],
        'smartphone' => ['Smartphone'],
        'server' => ['Server (Bundle)', 'Server (System Unit)'],
        'copier' => ['Copier / Xerox Machine'],
        'network_switch' => ['Network Switch'],
    ];

    public function up(): void
    {
        Schema::table('item_types', function (Blueprint $table) {
            $table->string('dashboard_group')->nullable()->after('description')->index();
        });

        foreach (self::GROUPS as $group => $names) {
            DB::table('item_types')
                ->whereIn('name', $names)
                ->update(['dashboard_group' => $group]);
        }
    }

    public function down(): void
    {
        Schema::table('item_types', function (Blueprint $table) {
            $table->dropIndex(['dashboard_group']);
            $table->dropColumn('dashboard_group');
        });
    }
};

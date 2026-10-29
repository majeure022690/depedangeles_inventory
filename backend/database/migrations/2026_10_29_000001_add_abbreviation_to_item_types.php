<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ABBREVIATIONS = [
        'Server (System Unit)' => 'SVU',
        'Thin Client Server (Set)' => 'TCS',
        'Server (Bundle)' => 'SVB',
        'Desktop (package)' => 'DST',
        'Laptop' => 'LPT',
        '2-in-1 Tablet' => '21T',
        'Tablet' => 'TBL',
        'Smartphone' => 'SMP',
        'External Hard Drive' => 'EHD',
        'USB Flash Drive' => 'UFD',
        'Smart TV' => 'SMT',
        'Standard TV' => 'STD',
        'Monitor' => 'MON',
        'CPU' => 'CPU',
        'Keyboard' => 'KYB',
        'Mouse' => 'MOS',
        'Trackpad' => 'TKP',
        'Stylus' => 'STL',
        'Signature pad' => 'SGP',
        'Memory Card (RAM)' => 'MMC',
        'Power Generator' => 'PWG',
        'UPS' => 'UPS',
        'AVR' => 'AVR',
        'Charging Cart' => 'CHC',
        'Software Licenses' => 'SFL',
        'Rack Cabinet' => 'RKC',
        'Pocket Wifi' => 'PWF',
        'Mobile Broadband USB Stick' => 'MUS',
        'Modem / Router' => 'MRT',
        'Access Point' => 'ACP',
        'Dish (Satellite Internet Communication System)' => 'SIC',
        'Network Switch' => 'NWS',
        'Firewall Appliance' => 'FWA',
        'Bluetooth / Wi-fi Adapter' => 'WFA',
        'Docking Station / USB Hub' => 'DSH',
        'PABX' => 'PBX',
        'Fax Machine' => 'FXM',
        'Computer Table' => 'CPT',
        'Printer' => 'PRT',
        'Scanner' => 'SCN',
        'Copier / Xerox Machine' => 'COP',
        'Multifunction Printer' => 'MFP',
        'Biometric Readers' => 'BMR',
        'Barcode or QR Scanner' => 'BQR',
        'Microphone' => 'MIC',
        'Lapel' => 'LPL',
        'Speakers' => 'SPK',
        'Headset with Microphone' => 'HDM',
        'Webcam' => 'WBC',
        'Digital Camera' => 'DGC',
        'Voice Recorder' => 'VRC',
        'Document cameras / Visualizers' => 'VIS',
        'Interactive Whiteboards / Smartboards' => 'IWB',
        'DVD Player' => 'DVP',
        'Projector' => 'PRJ',
        'Projector Screen' => 'PRS',
        'Laser pointer' => 'LSP',
        'Network Cable Tester' => 'NCT',
        'Crimper Tools' => 'CRT',
        'Cables Adaptor' => 'CBA',
        'Power Cords' => 'PWC',
        'Extension Cords' => 'EXC',
        'USB Charger' => 'USC',
        'Power bank' => 'PWB',
        'Others' => 'OTH',
    ];

    public function up(): void
    {
        Schema::table('item_types', function (Blueprint $table) {
            $table->string('abbreviation', 3)->nullable()->unique()->after('name');
        });

        DB::transaction(function () {
            $filled = [];

            foreach (self::ABBREVIATIONS as $name => $abbreviation) {
                $id = DB::table('item_types')->where('name', $name)->whereNull('abbreviation')->value('id');

                if ($id === null) {
                    continue;
                }

                DB::table('item_types')->where('id', $id)->update(['abbreviation' => $abbreviation]);

                $filled[] = ['id' => (int) $id, 'name' => $name, 'abbreviation' => $abbreviation];
            }

            if ($filled === []) {
                return;
            }

            $migration = pathinfo(__FILE__, PATHINFO_FILENAME);

            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => $migration,
                'event' => 'system.item_type_abbreviations_filled',
                'properties' => json_encode([
                    'migration' => $migration,
                    'filled_count' => count($filled),
                    'item_types' => $filled,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('item_types', function (Blueprint $table) {
            $table->dropUnique(['abbreviation']);
            $table->dropColumn('abbreviation');
        });
    }
};

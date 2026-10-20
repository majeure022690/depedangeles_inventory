<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing external DB connection via SSH tunnel...\n";
echo "Make sure SSH tunnel is running: ssh -L 3307:127.0.0.1:3306 user@31.97.50.236\n\n";

try {
    $count = DB::connection('external_users')->table('users')->count();
    echo "✓ Success! Found {$count} users in PRIME database.\n\n";
    
    echo "Sample users (first 5):\n";
    $users = DB::connection('external_users')->table('users')->limit(5)->get(['id', 'name', 'email']);
    foreach ($users as $user) {
        echo "  - {$user->name} ({$user->email})\n";
    }
    
    echo "\n✓ Connection working! You can now run: php artisan users:sync-external\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    echo "Troubleshooting:\n";
    echo "1. Make sure SSH tunnel is running in another terminal\n";
    echo "2. Check .env configuration:\n";
    echo "   EXTERNAL_DB_HOST=" . env('EXTERNAL_DB_HOST') . "\n";
    echo "   EXTERNAL_DB_PORT=" . env('EXTERNAL_DB_PORT') . " (should be 3307 for tunnel)\n";
    echo "   EXTERNAL_DB_DATABASE=" . env('EXTERNAL_DB_DATABASE') . "\n";
    echo "   EXTERNAL_DB_USERNAME=" . env('EXTERNAL_DB_USERNAME') . "\n";
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EquipmentTransactionDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrunePendingTransactionDocuments extends Command
{
    protected $signature = 'equipment:prune-pending-documents';

    protected $description = 'Delete supporting-document uploads that no transaction claimed within a day';

    public function handle(): int
    {
        $pruned = 0;

        EquipmentTransactionDocument::query()
            ->pending()
            ->where('created_at', '<', now()->subHours(EquipmentTransactionDocument::PENDING_HOURS))
            ->chunkById(200, function ($documents) use (&$pruned) {
                foreach ($documents as $document) {
                    if ($document->hasFile()) {
                        Storage::disk((string) $document->disk)->delete((string) $document->path);
                    }
                    $document->delete();
                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} unclaimed upload(s).");

        return self::SUCCESS;
    }
}

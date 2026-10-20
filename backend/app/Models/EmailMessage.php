<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One outgoing email, written by App\Listeners\RecordEmailMessage. Metadata
 * only (never the body or its links); recipients are encrypted at rest.
 * Not LogsActivity: this is itself a log, and clearing it is audited by
 * EmailLogController.
 *
 * @property int $id
 * @property string $recipients
 * @property string|null $subject
 * @property string|null $type
 * @property string $status
 * @property string|null $provider_message_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmailMessage extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'recipients',
        'subject',
        'type',
        'status',
        'provider_message_id',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'encrypted',
        ];
    }
}

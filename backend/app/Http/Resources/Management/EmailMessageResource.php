<?php

namespace App\Http\Resources\Management;

use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Email log row: delivery metadata only, never the body or its links.
 *
 * @mixin EmailMessage
 */
class EmailMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipients' => $this->recipients,
            'subject' => $this->subject,
            'type' => $this->type,
            'status' => $this->status,
            'provider_message_id' => $this->provider_message_id,
            'created_at' => $this->created_at,
        ];
    }
}

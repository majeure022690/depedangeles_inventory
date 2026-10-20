<?php

namespace App\Listeners;

use App\Models\EmailMessage;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use WeakMap;

/**
 * Records every outgoing email into the Settings > Email log: a `sending`
 * row on MessageSending, flipped to `sent` on MessageSent. Failures here
 * are reported, never allowed to block the email itself.
 */
class RecordEmailMessage
{
    /**
     * Friendly labels by notification/mailable class basename; anything
     * else falls back to the basename as a headline.
     */
    private const TYPE_LABELS = [
        'ResetPasswordNotification' => 'Password reset',
        'ResetPassword' => 'Password reset',
    ];

    /**
     * @var WeakMap<Email, int>|null
     */
    private static ?WeakMap $pending = null;

    public function sending(MessageSending $event): void
    {
        try {
            $record = EmailMessage::query()->create([
                'recipients' => collect([...$event->message->getTo(), ...$event->message->getCc(), ...$event->message->getBcc()])
                    ->map(fn (Address $address) => $address->getAddress())
                    ->implode(', '),
                'subject' => Str::limit((string) $event->message->getSubject(), 250, ''),
                'type' => $this->type($event->data),
                'status' => EmailMessage::STATUS_SENDING,
            ]);

            self::pending()[$event->message] = $record->id;
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function sent(MessageSent $event): void
    {
        $message = $event->sent->getOriginalMessage();

        if (! $message instanceof Email) {
            return;
        }

        $id = self::pending()[$message] ?? null;

        if ($id === null) {
            return;
        }

        unset(self::pending()[$message]);

        try {
            EmailMessage::query()->whereKey($id)->update([
                'status' => EmailMessage::STATUS_SENT,
                'provider_message_id' => $message->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function type(array $data): ?string
    {
        $class = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;

        if (! is_string($class)) {
            return null;
        }

        $name = class_basename($class);

        return self::TYPE_LABELS[$name] ?? Str::headline($name);
    }

    /**
     * @return WeakMap<Email, int>
     */
    private static function pending(): WeakMap
    {
        return self::$pending ??= new WeakMap;
    }
}

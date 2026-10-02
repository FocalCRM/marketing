<?php

declare(strict_types=1);

namespace Focal\Marketing\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;

/**
 * Hands marketing mail to the queue through the configured mailer
 * (focal-marketing.mail.mailer). The mailable carries its own connection and queue.
 */
final class MarketingMailer
{
    public static function queue(Mailable&ShouldQueue $mailable, string $email, ?string $name = null): void
    {
        Mail::mailer(self::mailerName())->to(new Address($email, $name))->queue($mailable);
    }

    public static function mailerName(): ?string
    {
        $mailer = config('focal-marketing.mail.mailer');

        return is_string($mailer) && $mailer !== '' ? $mailer : null;
    }
}

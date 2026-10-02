<?php

declare(strict_types=1);

namespace Focal\Marketing\Mail\Concerns;

/**
 * Routes a queued marketing mailable to the connection, queue and mailer set in
 * focal-marketing.mail. Empty values fall back to the application defaults.
 *
 * Like Sales and Service mail, the message is only pushed to the queue once the surrounding
 * database transaction commits (afterCommit), so a rolled-back transaction sends nothing.
 * Outside a transaction it is pushed straight away.
 */
trait UsesMarketingMailQueue
{
    protected function useMarketingMailQueue(): void
    {
        $connection = config('focal-marketing.mail.connection');
        $queue = config('focal-marketing.mail.queue');
        $mailer = config('focal-marketing.mail.mailer');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        if (is_string($mailer) && $mailer !== '') {
            $this->mailer($mailer);
        }

        $this->afterCommit();
    }
}

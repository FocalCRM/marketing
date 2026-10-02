<?php

declare(strict_types=1);

namespace Odden\Marketing\Mail;

use Odden\Marketing\Mail\Concerns\UsesMarketingMailQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A campaign proof for internal reviewers, queued on the queue set in odden-marketing.mail.
 */
class CampaignProofMailable extends Mailable implements ShouldQueue
{
    use Queueable, UsesMarketingMailQueue;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyToEmail = null
    ) {
        $this->useMarketingMailQueue();
    }

    public function envelope(): Envelope
    {
        $replyTo = ! empty($this->replyToEmail) ? [new Address($this->replyToEmail)] : [];

        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            replyTo: $replyTo,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
        );
    }
}

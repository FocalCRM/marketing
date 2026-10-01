<?php

declare(strict_types=1);

namespace Focal\Marketing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignProofMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyToEmail = null
    ) {}

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

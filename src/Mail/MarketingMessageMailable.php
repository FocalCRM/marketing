<?php

declare(strict_types=1);

namespace Odden\Marketing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Odden\Marketing\Mail\Concerns\UsesMarketingMailQueue;

/**
 * A compiled campaign or workflow email for one recipient: HTML plus a plain-text
 * alternative, List-Unsubscribe headers and the recipient's tracking token.
 * Always queued, on the queue set in odden-marketing.mail.
 */
class MarketingMessageMailable extends Mailable implements ShouldQueue
{
    use Queueable, UsesMarketingMailQueue;

    public const TRACKING_TOKEN_HEADER = 'X-Odden-Tracking-Token';

    /**
     * @param  string|null  $listUnsubscribeUrl  URL for the List-Unsubscribe header.
     * @param  bool  $oneClickUnsubscribe  The URL accepts RFC 8058 one-click POSTs (adds List-Unsubscribe-Post).
     * @param  string|null  $trackingToken  The campaign recipient's tracking token, for ESP webhook matching.
     */
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $textBody,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyToEmail = null,
        public ?string $listUnsubscribeUrl = null,
        public bool $oneClickUnsubscribe = false,
        public ?string $trackingToken = null,
    ) {
        $this->useMarketingMailQueue();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            replyTo: ! empty($this->replyToEmail) ? [new Address($this->replyToEmail)] : [],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        $text = [];

        if (! empty($this->listUnsubscribeUrl)) {
            $text['List-Unsubscribe'] = "<{$this->listUnsubscribeUrl}>";

            if ($this->oneClickUnsubscribe) {
                $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            }
        }

        if (! empty($this->trackingToken)) {
            $text[self::TRACKING_TOKEN_HEADER] = $this->trackingToken;
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        return new Content(
            text: 'odden-marketing::mail.text',
            htmlString: $this->htmlBody,
        );
    }
}

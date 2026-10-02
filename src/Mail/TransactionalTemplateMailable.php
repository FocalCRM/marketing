<?php

declare(strict_types=1);

namespace Odden\Marketing\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Odden\MailBuilder\Data\EmailDocument;
use Odden\MailBuilder\Mail\TemplateMailable;
use Odden\Marketing\Mail\Concerns\UsesMarketingMailQueue;

/**
 * A transactional API email, queued on the queue set in odden-marketing.mail.
 * The template is compiled and interpolated when the mailable is built, so the
 * queued job carries the final HTML.
 */
class TransactionalTemplateMailable extends TemplateMailable implements ShouldQueue
{
    use UsesMarketingMailQueue;

    /**
     * @param  EmailDocument|list<array<string, mixed>>|string  $template
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $customAttachments
     */
    public function __construct(
        EmailDocument|array|string $template,
        array $data = [],
        ?string $subjectLine = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyToEmail = null,
        ?string $listUnsubscribeUrl = null,
        array $customAttachments = [],
        bool $embedCidImages = false,
    ) {
        parent::__construct(
            template: $template,
            data: $data,
            subjectLine: $subjectLine,
            fromEmail: $fromEmail,
            fromName: $fromName,
            replyToEmail: $replyToEmail,
            listUnsubscribeUrl: $listUnsubscribeUrl,
            customAttachments: $customAttachments,
            embedCidImages: $embedCidImages,
        );

        $this->useMarketingMailQueue();
    }
}

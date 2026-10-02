<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Marketing\Mail\CampaignProofMailable;
use Focal\Marketing\Mail\MarketingMessageMailable;
use Focal\Marketing\Mail\TransactionalTemplateMailable;
use Focal\Marketing\Support\MarketingMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Marketing mail is queued after the surrounding database transaction commits, like Sales and
 * Service mail, so a rolled-back transaction never sends anything.
 */
class MarketingMailAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_marketing_mailable_waits_for_the_transaction_to_commit(): void
    {
        $mailables = [
            new MarketingMessageMailable(subjectLine: 'Hi', htmlBody: '<p>Hi</p>', textBody: 'Hi', fromEmail: 'news@example.com', fromName: 'News'),
            new CampaignProofMailable(subjectLine: 'Proof', htmlBody: '<p>Proof</p>', fromEmail: 'news@example.com', fromName: 'News'),
            new TransactionalTemplateMailable(template: '<p>Receipt</p>', subjectLine: 'Receipt'),
        ];

        foreach ($mailables as $mailable) {
            $this->assertTrue($mailable->afterCommit, $mailable::class);
        }
    }

    public function test_mail_queued_in_a_rolled_back_transaction_is_never_sent(): void
    {
        config(['queue.default' => 'sync', 'focal-marketing.mail.connection' => 'sync', 'focal-marketing.mail.mailer' => 'array']);

        try {
            DB::transaction(function (): void {
                MarketingMailer::queue($this->message('Rolled back'), 'ada@example.com');

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        DB::transaction(function (): void {
            MarketingMailer::queue($this->message('Committed'), 'ada@example.com');
        });

        $subjects = array_map(
            fn ($sent): string => (string) $sent->getOriginalMessage()->getSubject(),
            app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all(),
        );

        $this->assertSame(['Committed'], $subjects);
    }

    private function message(string $subject): MarketingMessageMailable
    {
        return new MarketingMessageMailable(subjectLine: $subject, htmlBody: '<p>Hi</p>', textBody: 'Hi', fromEmail: 'news@example.com', fromName: 'News');
    }
}

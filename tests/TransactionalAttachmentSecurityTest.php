<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use DoPHP\MailBuilder\Mail\TemplateMailable;
use Focal\Marketing\Models\MarketingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * The transactional API must never attach files from the server's filesystem.
 */
class TransactionalAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketingTemplate::create([
            'name' => 'Receipt',
            'slug' => 'receipt',
            'subject' => 'Receipt',
            'body_html' => '<p>Receipt</p>',
        ]);
    }

    public function test_attachment_paths_are_rejected(): void
    {
        Mail::fake();

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'attachments' => [
                ['name' => 'env.txt', 'path' => base_path('.env')],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0.path');

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'attachments' => [
                ['name' => 'env.txt', 'path' => __FILE__, 'data' => base64_encode('x')],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0.path');

        Mail::assertNothingOutgoing();
    }

    public function test_attachments_require_base64_data(): void
    {
        Mail::fake();

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'attachments' => [['name' => 'a.txt']],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0.data');

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'attachments' => [['name' => 'a.txt', 'data' => 'not base64!']],
        ])->assertStatus(422)->assertJsonValidationErrors('attachments.0.data');

        Mail::assertNothingOutgoing();
    }

    public function test_base64_attachments_are_sent_decoded(): void
    {
        Mail::fake();

        $this->postJson(route('focal.marketing.templates.send', ['template' => 'receipt']), [
            'to' => 'sam@example.com',
            'attachments' => [
                ['name' => 'note.txt', 'data' => base64_encode('hello world'), 'mime' => 'text/plain'],
            ],
        ])->assertOk();

        Mail::assertQueued(TemplateMailable::class, function (TemplateMailable $mailable): bool {
            $this->assertSame([['name' => 'note.txt', 'data' => base64_encode('hello world'), 'mime' => 'text/plain', 'is_base64' => true]], $mailable->customAttachments);

            return true;
        });
    }
}

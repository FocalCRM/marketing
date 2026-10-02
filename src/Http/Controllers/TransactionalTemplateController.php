<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Closure;
use Odden\MailBuilder\MailBuilder;
use Odden\Marketing\Mail\TransactionalTemplateMailable;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Services\DomainThrottler;
use Odden\Marketing\Services\MarketingWebhookDispatcher;
use Odden\Marketing\Support\MarketingMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TransactionalTemplateController extends Controller
{
    /**
     * Queue a transactional email using a pre-built MarketingTemplate. Returns once the
     * message is on the queue (odden-marketing.mail); a queue worker delivers it.
     */
    public function send(Request $request, string|int $template): JsonResponse
    {
        /** @var MarketingTemplate|null $record */
        $record = is_numeric($template)
            ? MarketingTemplate::query()->find($template)
            : MarketingTemplate::query()->where('slug', $template)->first();

        if ($record === null) {
            return response()->json([
                'error' => 'Marketing template not found.',
            ], 404);
        }

        $validated = $request->validate([
            'to' => 'required|email|max:255',
            'name' => 'nullable|string|max:255',
            'data' => 'nullable|array',
            'context' => 'nullable|array',
            'subject' => 'nullable|string|max:255',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
            'variant' => 'nullable|string|in:A,B,a,b',
            'webhook_url' => 'nullable|url|max:500',
            'webhook_secret' => 'nullable|string|max:255',
            'attachments' => 'nullable|array',
            'attachments.*.name' => 'required_with:attachments|string|max:255',
            // Attachments are inline base64 only: a server-side path would let any
            // token holder read files such as .env.
            'attachments.*.path' => 'prohibited',
            'attachments.*.data' => [
                'required_with:attachments',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || base64_decode($value, true) === false) {
                        $fail('The :attribute field must be base64 encoded.');
                    }
                },
            ],
            'attachments.*.mime' => 'nullable|string|max:100',
            'attachments.*.is_base64' => 'sometimes|accepted',
        ]);

        /** @var string $to */
        $to = (string) $validated['to'];
        /** @var array<string, mixed> $data */
        $data = isset($validated['data']) && is_array($validated['data']) ? $validated['data'] : [];
        /** @var array<string, mixed> $context */
        $context = isset($validated['context']) && is_array($validated['context']) ? $validated['context'] : [];

        // If recipient name is provided, map to default tokens if not present
        if (! empty($validated['name']) && ! isset($data['contact.first_name'])) {
            $data['contact.first_name'] = (string) $validated['name'];
        }

        $variant = isset($validated['variant'])
            ? strtoupper((string) $validated['variant'])
            : ($record->ab_winner_variant ?? 'A');

        $slots = ($variant === 'B' && ! empty($record->slots_variant_b))
            ? $record->slots_variant_b
            : $record->slots;

        $subject = ! empty($validated['subject'])
            ? (string) $validated['subject']
            : $record->getVariantSubject($variant);

        if (! empty($slots)) {
            $html = MailBuilder::compile($slots, [
                'theme' => $record->theme ?? [],
                'context' => $context,
                'subject' => $subject,
            ]);
        } else {
            $html = $record->getVariantHtml($variant);
        }

        $mailable = new TransactionalTemplateMailable(
            template: $html,
            data: $data,
            subjectLine: $subject,
            fromEmail: isset($validated['from_email']) ? (string) $validated['from_email'] : null,
            fromName: isset($validated['from_name']) ? (string) $validated['from_name'] : null,
            replyToEmail: isset($validated['reply_to']) ? (string) $validated['reply_to'] : null,
            customAttachments: $this->inlineAttachments($validated['attachments'] ?? []),
        );

        $recipientName = isset($validated['name']) ? (string) $validated['name'] : null;
        MarketingMailer::queue($mailable, $to, $recipientName);

        if (! empty($validated['webhook_url'])) {
            MarketingWebhookDispatcher::dispatch(
                event: 'template.email.sent',
                data: [
                    'template_id' => $record->id,
                    'template_slug' => $record->slug,
                    'recipient' => $to,
                    'variant' => $variant,
                    'subject' => $mailable->subjectLine,
                ],
                endpointUrl: (string) $validated['webhook_url'],
                secret: isset($validated['webhook_secret']) ? (string) $validated['webhook_secret'] : null
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Transactional email queued for delivery.',
            'queued' => true,
            'template_id' => $record->id,
            'template_slug' => $record->slug,
            'recipient' => $to,
            'variant' => $variant,
            'subject' => $mailable->subjectLine,
        ]);
    }

    /**
     * Queue a batch of transactional emails (up to 1,000) using a pre-built MarketingTemplate.
     */
    public function sendBatch(Request $request, string|int $template): JsonResponse
    {
        /** @var MarketingTemplate|null $record */
        $record = is_numeric($template)
            ? MarketingTemplate::query()->find($template)
            : MarketingTemplate::query()->where('slug', $template)->first();

        if ($record === null) {
            return response()->json([
                'error' => 'Marketing template not found.',
            ], 404);
        }

        $validated = $request->validate([
            'recipients' => 'required|array|min:1|max:1000',
            'recipients.*.to' => 'required|email|max:255',
            'recipients.*.name' => 'nullable|string|max:255',
            'recipients.*.data' => 'nullable|array',
            'subject' => 'nullable|string|max:255',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
            'variant' => 'nullable|string|in:A,B,a,b',
            'webhook_url' => 'nullable|url|max:500',
            'webhook_secret' => 'nullable|string|max:255',
            'throttle_domains' => 'nullable|boolean',
        ]);

        $variant = isset($validated['variant'])
            ? strtoupper((string) $validated['variant'])
            : ($record->ab_winner_variant ?? 'A');

        $slots = ($variant === 'B' && ! empty($record->slots_variant_b))
            ? $record->slots_variant_b
            : $record->slots;

        $subject = ! empty($validated['subject'])
            ? (string) $validated['subject']
            : $record->getVariantSubject($variant);

        if (! empty($slots)) {
            $baseHtml = MailBuilder::compile($slots, [
                'theme' => $record->theme ?? [],
                'subject' => $subject,
            ]);
        } else {
            $baseHtml = $record->getVariantHtml($variant);
        }

        /** @var list<array{to: string, name?: string, data?: array<string, mixed>}> $recipients */
        $recipients = $validated['recipients'];
        $dispatched = [];

        foreach ($recipients as $recipient) {
            $toEmail = $recipient['to'];
            $recipientName = $recipient['name'] ?? null;
            $data = $recipient['data'] ?? [];

            if (! empty($recipientName) && ! isset($data['contact.first_name'])) {
                $data['contact.first_name'] = $recipientName;
            }

            $mailable = new TransactionalTemplateMailable(
                template: $baseHtml,
                data: $data,
                subjectLine: $subject,
                fromEmail: isset($validated['from_email']) ? (string) $validated['from_email'] : null,
                fromName: isset($validated['from_name']) ? (string) $validated['from_name'] : null,
                replyToEmail: isset($validated['reply_to']) ? (string) $validated['reply_to'] : null,
            );

            MarketingMailer::queue($mailable, $toEmail, $recipientName);
            $dispatched[] = $toEmail;
        }

        if (! empty($validated['webhook_url'])) {
            MarketingWebhookDispatcher::dispatch(
                event: 'template.email.batch_sent',
                data: [
                    'template_id' => $record->id,
                    'template_slug' => $record->slug,
                    'dispatched_count' => count($dispatched),
                    'recipients' => $dispatched,
                    'variant' => $variant,
                ],
                endpointUrl: (string) $validated['webhook_url'],
                secret: isset($validated['webhook_secret']) ? (string) $validated['webhook_secret'] : null
            );
        }

        $response = [
            'success' => true,
            'message' => 'Batch transactional emails queued for delivery.',
            'queued' => true,
            'template_id' => $record->id,
            'template_slug' => $record->slug,
            'dispatched_count' => count($dispatched),
            'recipients' => $dispatched,
        ];

        if (! empty($validated['throttle_domains'])) {
            $response['throttle_plan'] = DomainThrottler::calculateThrottledBatches($recipients);
        }

        return response()->json($response);
    }

    /**
     * Rebuild validated attachments with only the inline fields, so nothing but
     * base64 content ever reaches the mailable.
     *
     * @return list<array{name: string, data: string, mime: string|null, is_base64: true}>
     */
    private function inlineAttachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $inline = [];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $inline[] = [
                'name' => (string) ($attachment['name'] ?? 'attachment'),
                'data' => (string) ($attachment['data'] ?? ''),
                'mime' => isset($attachment['mime']) ? (string) $attachment['mime'] : null,
                'is_base64' => true,
            ];
        }

        return $inline;
    }
}

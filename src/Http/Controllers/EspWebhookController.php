<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Odden\Marketing\Actions\ProcessEspWebhookAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EspWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from ESPs (Mailgun, SES, Postmark, Resend, Sendgrid, etc.).
     */
    public function handle(Request $request, string $provider, ProcessEspWebhookAction $action): JsonResponse
    {
        /** @var array<string, mixed>|list<array<string, mixed>> $payload */
        $payload = $request->all();

        if (array_is_list($payload)) {
            $processed = [];
            foreach ($payload as $single) {
                if (is_array($single)) {
                    $ev = $action->execute($provider, $single);
                    $processed[] = $ev->id;
                }
            }

            return response()->json([
                'status' => 'received',
                'count' => count($processed),
                'event_ids' => $processed,
            ]);
        }

        $event = $action->execute($provider, $payload);

        return response()->json([
            'status' => 'received',
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);
    }

    /**
     * Unified generic deliverability endpoint (/api/marketing/webhooks/deliverability).
     */
    public function deliverability(Request $request, ProcessEspWebhookAction $action): JsonResponse
    {
        $provider = (string) $request->input('provider', 'generic');

        return $this->handle($request, $provider, $action);
    }
}

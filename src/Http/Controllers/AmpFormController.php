<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\MarketingEvent;
use Focal\Marketing\Models\MarketingEventRegistration;
use Focal\Marketing\Models\NpsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AmpFormController extends Controller
{
    /**
     * Handle in-email AMP 1-click rating or feedback form submission.
     */
    public function feedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'nullable|string|max:255',
            'score' => 'required|integer|min:0|max:10',
            'feedback' => 'nullable|string|max:2000',
            'email' => 'nullable|email|max:255',
        ]);

        $score = (int) $validated['score'];
        $feedback = isset($validated['feedback']) ? (string) $validated['feedback'] : null;
        $token = isset($validated['token']) ? (string) $validated['token'] : null;

        if (! empty($token)) {
            $nps = NpsResponse::query()->where('token', $token)->first();
            if ($nps !== null) {
                $nps->score = $score;
                if ($feedback !== null) {
                    $nps->feedback = $feedback;
                }
                $nps->responded_at = now();
                $nps->save();
            }
        }

        return $this->ampResponse($request, [
            'status' => 'success',
            'message' => 'Thank you! Your feedback has been recorded.',
            'score' => $score,
        ]);
    }

    /**
     * Handle in-email AMP event RSVP form submission.
     */
    public function rsvp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_slug' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'status' => 'nullable|string|in:attending,declined,tentative',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
        ]);

        $eventSlug = (string) $validated['event_slug'];
        $email = (string) $validated['email'];
        $status = isset($validated['status']) ? (string) $validated['status'] : 'attending';

        $event = MarketingEvent::query()->where('slug', $eventSlug)->first();

        if ($event !== null) {
            $contact = Contact::query()->firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $validated['first_name'] ?? 'Attendee',
                    'last_name' => $validated['last_name'] ?? '',
                ]
            );

            MarketingEventRegistration::query()->updateOrCreate(
                [
                    'event_id' => $event->id,
                    'contact_id' => $contact->id,
                ],
                [
                    'status' => $status,
                    'registered_at' => now(),
                ]
            );
        }

        return $this->ampResponse($request, [
            'status' => 'success',
            'message' => 'Your RSVP has been saved successfully.',
            'event' => $eventSlug,
            'rsvp_status' => $status,
        ]);
    }

    /**
     * Return a JsonResponse with standard AMP CORS headers.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ampResponse(Request $request, array $data, int $status = 200): JsonResponse
    {
        $sourceOrigin = $request->query('__amp_source_origin');
        $origin = $request->header('Origin', '*');

        $response = response()->json($data, $status);

        $response->headers->set('Access-Control-Allow-Origin', (string) $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', 'AMP-Access-Control-Allow-Source, AMP-Email-Allow-Sender');

        if (! empty($sourceOrigin) && is_string($sourceOrigin)) {
            $response->headers->set('AMP-Access-Control-Allow-Source', $sourceOrigin);
        }

        return $response;
    }
}

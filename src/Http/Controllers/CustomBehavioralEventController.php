<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\TrackCustomBehavioralEventAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CustomBehavioralEventController extends Controller
{
    /**
     * Ingest and track in-app custom behavioral events via API.
     */
    public function track(Request $request, TrackCustomBehavioralEventAction $action): JsonResponse
    {
        $validated = $request->validate([
            'event_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'contact_id' => ['nullable', 'numeric'],
            'properties' => ['nullable', 'array'],
        ]);

        $eventName = (string) $validated['event_name'];
        $email = isset($validated['email']) ? (string) $validated['email'] : null;
        $contactId = isset($validated['contact_id']) ? (int) $validated['contact_id'] : null;
        /** @var array<string, mixed> $properties */
        $properties = $validated['properties'] ?? [];

        $contact = null;
        if ($contactId !== null) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->find($contactId);
        }

        $event = $action->execute(
            eventName: $eventName,
            contact: $contact,
            email: $email,
            properties: $properties
        );

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
            'event_name' => $event->event_name,
            'contact_id' => $event->contact_id,
            'company_id' => $event->company_id,
            'message' => 'Behavioral event tracked and processed successfully.',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Odden\Marketing\Actions\IngestExternalLeadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ExternalLeadWebhookController extends Controller
{
    /**
     * Handle inbound webhook lead ingestion (Zapier, LinkedIn Lead Gen, Zoom, etc.).
     */
    public function handle(Request $request, IngestExternalLeadAction $action, ?string $source = null): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'properties' => ['nullable', 'array'],
        ]);

        $leadSource = $source ?? (string) $request->input('source', 'webhook');

        $payload = [
            'email' => (string) $validated['email'],
            'first_name' => isset($validated['first_name']) ? (string) $validated['first_name'] : null,
            'last_name' => isset($validated['last_name']) ? (string) $validated['last_name'] : null,
            'company' => isset($validated['company']) ? (string) $validated['company'] : null,
            'phone' => isset($validated['phone']) ? (string) $validated['phone'] : null,
            'job_title' => isset($validated['job_title']) ? (string) $validated['job_title'] : null,
            'campaign' => isset($validated['campaign']) ? (string) $validated['campaign'] : null,
            'source' => $leadSource,
        ];

        if (isset($validated['properties']) && is_array($validated['properties'])) {
            $payload['properties'] = $validated['properties'];
        }

        $result = $action->execute($payload);

        return response()->json([
            'success' => true,
            'contact_id' => $result['contact']->id,
            'is_new' => $result['is_new'],
            'lead_score' => $result['lead_score'],
            'enrolled_workflows' => $result['enrolled_workflows_count'],
            'message' => 'Lead successfully ingested into Odden CRM.',
        ], 200);
    }
}

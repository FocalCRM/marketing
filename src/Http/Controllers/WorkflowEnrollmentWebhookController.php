<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Core\Enums\LeadStatus;
use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\EnrollContactInWorkflowAction;
use Focal\Marketing\Models\MarketingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkflowEnrollmentWebhookController extends Controller
{
    /**
     * Ingest an external event/webhook payload to find or create a contact
     * and enroll them into the specified workflow.
     */
    public function enroll(
        Request $request,
        string|int $workflow,
        EnrollContactInWorkflowAction $enrollAction
    ): JsonResponse {
        /** @var MarketingWorkflow|null $workflowModel */
        $workflowModel = is_numeric($workflow)
            ? MarketingWorkflow::query()->find((int) $workflow)
            : MarketingWorkflow::query()->where('name', $workflow)->first();

        if ($workflowModel === null) {
            return response()->json([
                'success' => false,
                'message' => "Workflow [{$workflow}] not found.",
            ], 404);
        }

        if (! $workflowModel->is_active) {
            return response()->json([
                'success' => false,
                'message' => "Workflow [{$workflowModel->name}] is currently inactive.",
            ], 422);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'trigger_event' => ['nullable', 'string', 'max:100'],
            'properties' => ['nullable', 'array'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        /** @var Contact|null $contact */
        $contact = Contact::query()->where('email', $email)->first();

        if ($contact === null) {
            $contact = Contact::create([
                'email' => $email,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'lifecycle_stage' => LifecycleStage::Lead,
                'lead_status' => LeadStatus::New,
            ]);
        } else {
            $updates = [];
            if (empty($contact->first_name) && ! empty($validated['first_name'])) {
                $updates['first_name'] = $validated['first_name'];
            }
            if (empty($contact->last_name) && ! empty($validated['last_name'])) {
                $updates['last_name'] = $validated['last_name'];
            }
            if (empty($contact->phone) && ! empty($validated['phone'])) {
                $updates['phone'] = $validated['phone'];
            }
            if (! empty($updates)) {
                $contact->update($updates);
            }
        }

        // Handle custom properties
        if (! empty($validated['properties'])) {
            foreach ($validated['properties'] as $key => $value) {
                if (is_scalar($value)) {
                    $contact->setProperty((string) $key, $value);
                }
            }
        }

        // Associate company if supplied
        if (! empty($validated['company'])) {
            $companyName = trim((string) $validated['company']);
            /** @var Company|null $company */
            $company = Company::query()->where('name', $companyName)->first();
            if ($company === null) {
                $company = Company::create(['name' => $companyName]);
            }
            if (! $contact->isAssociatedWith($company)) {
                $contact->associateWith($company);
            }
        }

        $triggerEvent = (string) ($validated['trigger_event'] ?? 'inbound_webhook');

        // Log timeline task
        $contact->logTask(
            title: "Webhook Enrollment: {$workflowModel->name}",
            dueAt: now(),
            body: "Contact enrolled in [{$workflowModel->name}] via external webhook trigger ({$triggerEvent})."
        );

        $enrollment = $enrollAction->execute($workflowModel, $contact);

        if ($enrollment === null) {
            return response()->json([
                'success' => true,
                'workflow_id' => $workflowModel->id,
                'workflow_name' => $workflowModel->name,
                'contact_id' => $contact->id,
                'enrollment_id' => null,
                'status' => 'pending_steps',
                'message' => 'Contact registered; workflow has no defined steps yet.',
            ], 201);
        }

        return response()->json([
            'success' => true,
            'workflow_id' => $workflowModel->id,
            'workflow_name' => $workflowModel->name,
            'contact_id' => $contact->id,
            'enrollment_id' => $enrollment->id,
            'status' => $enrollment->status,
            'message' => 'Contact successfully enrolled in workflow.',
        ], 201);
    }
}

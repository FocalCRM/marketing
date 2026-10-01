<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Enums\LeadStatus;
use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\LeadScoringEventType;
use Focal\Marketing\Models\FormSubmission;
use Focal\Marketing\Models\MarketingForm;

class ProcessFormSubmissionAction
{
    /**
     * Process an incoming form submission, auto-provision contact/company, and record activity.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        MarketingForm $form,
        array $data,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): FormSubmission {
        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $firstName = isset($data['first_name']) ? trim((string) $data['first_name']) : null;
        $lastName = isset($data['last_name']) ? trim((string) $data['last_name']) : null;
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        $companyName = isset($data['company']) ? trim((string) $data['company']) : null;

        $contact = null;
        if (! empty($data['contact_id']) && is_numeric($data['contact_id'])) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->find((int) $data['contact_id']);
        }

        if ($contact === null && ! empty($email)) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->where('email', $email)->first();

            if ($contact === null) {
                $contact = Contact::create([
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'lead_status' => LeadStatus::New,
                    'lifecycle_stage' => LifecycleStage::Lead,
                ]);
            } else {
                $updates = [];
                if (empty($contact->first_name) && ! empty($firstName)) {
                    $updates['first_name'] = $firstName;
                }
                if (empty($contact->last_name) && ! empty($lastName)) {
                    $updates['last_name'] = $lastName;
                }
                if (empty($contact->phone) && ! empty($phone)) {
                    $updates['phone'] = $phone;
                }
                if (! empty($updates)) {
                    $contact->update($updates);
                }
            }

            // Handle SMS Consent if opted in
            if (! empty($data['sms_consent'])) {
                $contact->update([
                    'sms_consent' => true,
                    'sms_consent_at' => now(),
                ]);
            }

            // Stitch anonymous visitor sessions if visitor_token was passed
            if (! empty($data['visitor_token'])) {
                app(StitchVisitorToContactAction::class)->execute((string) $data['visitor_token'], $contact);
            }

            // Link company if provided, or perform Lead-to-Account domain auto-match
            if (! empty($companyName)) {
                /** @var Company|null $company */
                $company = Company::query()->where('name', $companyName)->first();
                if ($company === null) {
                    $company = Company::create(['name' => $companyName]);
                }

                if (! $contact->isAssociatedWith($company)) {
                    $contact->associateWith($company);
                }
            } else {
                app(AutoMatchLeadToCompanyAction::class)->execute($contact);
            }

            // Log activity on Contact timeline
            $contact->logTask(
                title: "Form Submission: {$form->title}",
                dueAt: now(),
                body: "Contact submitted marketing form [{$form->title}]."
            );

            // Apply Lead Scoring Event (+15 pts default)
            app(ApplyLeadScoringEventAction::class)->execute(
                contact: $contact,
                eventType: LeadScoringEventType::FormSubmission,
                description: "Submitted form: {$form->title}",
            );
        }

        if ($contact !== null) {
            $excludedKeys = [
                '_token', 'email', 'first_name', 'last_name', 'phone', 'company',
                'sms_consent', 'visitor_token', 'contact_id',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            ];
            $customProps = array_diff_key($data, array_flip($excludedKeys));
            if (! empty($customProps)) {
                $contact->setProperties($customProps)->save();
            }
        }

        /** @var FormSubmission $submission */
        $submission = $form->submissions()->create([
            'contact_id' => $contact?->id,
            'form_data' => $data,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'utm_source' => isset($data['utm_source']) ? (string) $data['utm_source'] : null,
            'utm_medium' => isset($data['utm_medium']) ? (string) $data['utm_medium'] : null,
            'utm_campaign' => isset($data['utm_campaign']) ? (string) $data['utm_campaign'] : null,
            'utm_term' => isset($data['utm_term']) ? (string) $data['utm_term'] : null,
            'utm_content' => isset($data['utm_content']) ? (string) $data['utm_content'] : null,
        ]);

        $form->increment('submissions_count');

        if ($contact !== null) {
            app(EnrollContactInWorkflowAction::class)->triggerFormWorkflows($form, $contact);
        }

        return $submission;
    }
}

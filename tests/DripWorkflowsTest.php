<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\EnrollContactInWorkflowAction;
use Focal\Marketing\Actions\ProcessFormSubmissionAction;
use Focal\Marketing\Enums\WorkflowEnrollmentStatus;
use Focal\Marketing\Enums\WorkflowStepType;
use Focal\Marketing\Enums\WorkflowTriggerType;
use Focal\Marketing\Models\MarketingForm;
use Focal\Marketing\Models\MarketingWorkflow;
use Focal\Marketing\Models\WorkflowEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DripWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_enrollment_executes_email_and_schedules_delay(): void
    {
        $contact = Contact::create([
            'first_name' => 'Linus',
            'last_name' => 'Torvalds',
            'email' => 'linus@kernel.org',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 20,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'New User Onboarding',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $step1 = $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send Welcome Email',
            'type' => WorkflowStepType::SendEmail,
            'config' => [
                'subject' => 'Welcome to Focal CRM, {{ first_name }}!',
                'body' => '<p>Hello {{ first_name }}, thanks for joining!</p>',
            ],
        ]);

        $step2 = $workflow->steps()->create([
            'step_number' => 2,
            'name' => 'Wait 2 Days',
            'type' => WorkflowStepType::Delay,
            'config' => [
                'delay_minutes' => 2880, // 2 days
            ],
        ]);

        $step3 = $workflow->steps()->create([
            'step_number' => 3,
            'name' => 'Promote to MQL',
            'type' => WorkflowStepType::UpdateContact,
            'config' => [
                'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead->value,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contact);

        $this->assertNotNull($enrollment);
        $this->assertSame(WorkflowEnrollmentStatus::Active, $enrollment->status);
        $this->assertSame(1, $workflow->fresh()->enrollments_count);

        // Step 1 (SendEmail) and Step 2 (Delay) executed, staging Step 3 for future execution
        $enrollment->refresh();
        $this->assertSame($step3->id, $enrollment->current_step_id);
        $this->assertNotNull($enrollment->next_run_at);
        $this->assertTrue($enrollment->next_run_at->isFuture());

        // Check workflow logs
        $logs = $enrollment->logs;
        $this->assertCount(2, $logs); // 1 email sent log + 1 wait timer started log
        $this->assertStringContainsString('Sent email', $logs[0]->action_taken);
        $this->assertStringContainsString('Wait timer started', $logs[1]->action_taken);
    }

    public function test_process_due_workflows_advances_and_evaluates_condition_branching(): void
    {
        $contactQualified = Contact::create([
            'first_name' => 'Steve',
            'last_name' => 'Wozniak',
            'email' => 'woz@apple.com',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 80,
        ]);

        $contactUnqualified = Contact::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'johndoe@test.com',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 15,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Score-based Qualification Journey',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        // Step 1: Condition branching based on lead_score >= 50
        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Check Lead Score Threshold',
            'type' => WorkflowStepType::Condition,
            'config' => [
                'property' => 'lead_score',
                'operator' => '>=',
                'value' => 50,
            ],
            'next_step_on_true' => 2,
            'next_step_on_false' => 3,
        ]);

        // Step 2 (True path): Promote to SQL and terminate workflow
        $workflow->steps()->create([
            'step_number' => 2,
            'name' => 'Qualify as SQL',
            'type' => WorkflowStepType::UpdateContact,
            'config' => [
                'lifecycle_stage' => LifecycleStage::SalesQualifiedLead->value,
                'next_step' => null,
            ],
        ]);

        // Step 3 (False path): Demote to Subscriber
        $workflow->steps()->create([
            'step_number' => 3,
            'name' => 'Set as Subscriber',
            'type' => WorkflowStepType::UpdateContact,
            'config' => [
                'lifecycle_stage' => LifecycleStage::Subscriber->value,
                'next_step' => null,
            ],
        ]);

        $enrollAction = new EnrollContactInWorkflowAction;

        // Test Qualified Contact (Condition -> True -> Step 2)
        $enrollmentQ = $enrollAction->execute($workflow, $contactQualified);
        $enrollmentQ->refresh();
        $contactQualified->refresh();
        $this->assertSame(WorkflowEnrollmentStatus::Completed, $enrollmentQ->status);
        $this->assertSame(LifecycleStage::SalesQualifiedLead, $contactQualified->lifecycle_stage);

        // Test Unqualified Contact (Condition -> False -> Step 3)
        $enrollmentU = $enrollAction->execute($workflow, $contactUnqualified);
        $enrollmentU->refresh();
        $contactUnqualified->refresh();
        $this->assertSame(WorkflowEnrollmentStatus::Completed, $enrollmentU->status);
        $this->assertSame(LifecycleStage::Subscriber, $contactUnqualified->lifecycle_stage);
    }

    public function test_artisan_process_workflows_command_runs_due_enrollments(): void
    {
        $contact = Contact::create([
            'first_name' => 'Margaret',
            'last_name' => 'Hamilton',
            'email' => 'margaret@apollo.nasa',
            'lifecycle_stage' => LifecycleStage::Lead,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Delayed Promotion',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $step1 = $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Promote Contact',
            'type' => WorkflowStepType::UpdateContact,
            'config' => [
                'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead->value,
            ],
        ]);

        // Create an enrollment that was scheduled in the past
        $enrollment = WorkflowEnrollment::create([
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'current_step_id' => $step1->id,
            'status' => WorkflowEnrollmentStatus::Active,
            'enrolled_at' => now()->subHours(5),
            'next_run_at' => now()->subHours(1),
        ]);

        $this->artisan('marketing:process-workflows')
            ->expectsOutputToContain('Advanced 1 workflow enrollment(s) successfully.')
            ->assertExitCode(0);

        $enrollment->refresh();
        $contact->refresh();

        $this->assertSame(WorkflowEnrollmentStatus::Completed, $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertSame(LifecycleStage::MarketingQualifiedLead, $contact->lifecycle_stage);
    }

    public function test_form_submission_automatically_triggers_matching_workflow(): void
    {
        $form = MarketingForm::create([
            'title' => 'Request Enterprise Demo',
            'slug' => 'request-enterprise-demo',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ],
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Enterprise Demo Nurture',
            'trigger_type' => WorkflowTriggerType::FormSubmitted,
            'trigger_config' => [
                'form_id' => $form->id,
            ],
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send Demo Confirmation',
            'type' => WorkflowStepType::SendEmail,
            'config' => [
                'subject' => 'Your demo is booked',
                'body' => 'Thank you for reaching out.',
            ],
        ]);

        $submissionAction = new ProcessFormSubmissionAction;
        $submission = $submissionAction->execute($form, [
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => 'katherine@nasa.test',
        ]);

        $contact = $submission->contact;
        $this->assertNotNull($contact);

        $this->assertDatabaseHas('focal_marketing_workflow_enrollments', [
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'status' => WorkflowEnrollmentStatus::Completed->value,
        ]);
    }
}

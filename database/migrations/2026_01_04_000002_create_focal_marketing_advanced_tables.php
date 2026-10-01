<?php

declare(strict_types=1);

use Focal\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $contactsTable = config('focal-core.tables.contacts', 'focal_contacts');
        $listsTable = config('focal-core.tables.lists', 'focal_lists');
        $templatesTable = config('focal-marketing.tables.templates', 'focal_marketing_templates');
        $campaignsTable = config('focal-marketing.tables.campaigns', 'focal_marketing_campaigns');
        $recipientsTable = config('focal-marketing.tables.recipients', 'focal_marketing_campaign_recipients');
        $submissionsTable = config('focal-marketing.tables.form_submissions', 'focal_marketing_form_submissions');

        $rulesTable = config('focal-marketing.tables.scoring_rules', 'focal_marketing_lead_scoring_rules');
        $scoreLogsTable = config('focal-marketing.tables.score_logs', 'focal_marketing_lead_score_logs');
        $workflowsTable = config('focal-marketing.tables.workflows', 'focal_marketing_workflows');
        $stepsTable = config('focal-marketing.tables.workflow_steps', 'focal_marketing_workflow_steps');
        $enrollmentsTable = config('focal-marketing.tables.workflow_enrollments', 'focal_marketing_workflow_enrollments');
        $workflowLogsTable = config('focal-marketing.tables.workflow_logs', 'focal_marketing_workflow_logs');

        // 1. Add lead score to contacts table
        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->integer('lead_score')->default(0)->after('lifecycle_stage');
            $table->timestamp('lead_score_updated_at')->nullable()->after('lead_score');
        });

        // 2. Lead Scoring Rules Table
        Schema::create($rulesTable, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('event_type', 40)->index(); // form_submission, email_opened, email_clicked, inactivity_decay, unsubscribed, property_match
            $table->json('conditions')->nullable();
            $table->integer('score_change');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Lead Score Logs Table
        Schema::create($scoreLogsTable, function (Blueprint $table) use ($contactsTable, $rulesTable): void {
            $table->id();
            $table->foreignId('contact_id')->constrained($contactsTable)->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained($rulesTable)->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('event_description');
            $table->integer('score_change');
            $table->integer('score_after');
            $table->timestamp('created_at')->useCurrent();
        });

        // 4. Marketing Workflows Table
        Schema::create($workflowsTable, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type', 40)->index(); // form_submitted, contact_created, list_joined, lead_score_reached, manual
            $table->json('trigger_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('enrollments_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->foreignIdFor(UserModel::className(), 'created_by_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // 5. Workflow Steps Table
        Schema::create($stepsTable, function (Blueprint $table) use ($workflowsTable): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained($workflowsTable)->cascadeOnDelete();
            $table->unsignedInteger('step_number')->index();
            $table->string('type', 30); // send_email, delay, condition, update_contact
            $table->json('config');
            $table->unsignedInteger('next_step_on_true')->nullable();
            $table->unsignedInteger('next_step_on_false')->nullable();
            $table->timestamps();
        });

        // 6. Workflow Enrollments Table
        Schema::create($enrollmentsTable, function (Blueprint $table) use ($workflowsTable, $contactsTable, $stepsTable): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained($workflowsTable)->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained($contactsTable)->cascadeOnDelete();
            $table->foreignId('current_step_id')->nullable()->constrained($stepsTable)->nullOnDelete();
            $table->string('status', 20)->default('active')->index(); // active, paused, completed, exited
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 7. Workflow Execution Logs Table
        Schema::create($workflowLogsTable, function (Blueprint $table) use ($enrollmentsTable, $stepsTable, $contactsTable): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained($enrollmentsTable)->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained($stepsTable)->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained($contactsTable)->cascadeOnDelete();
            $table->string('action_taken');
            $table->string('status', 20)->default('success'); // success, skipped, failed
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // 8. Add A/B Testing & Audience List fields to Campaigns
        Schema::table($campaignsTable, function (Blueprint $table) use ($listsTable, $templatesTable): void {
            $table->foreignId('crm_list_id')->nullable()->after('campaign_type')->constrained($listsTable)->nullOnDelete();
            $table->boolean('is_ab_test')->default(false)->after('scheduled_at');
            $table->string('variant_b_subject')->nullable()->after('is_ab_test');
            $table->foreignId('variant_b_template_id')->nullable()->after('variant_b_subject')->constrained($templatesTable)->nullOnDelete();
            $table->unsignedTinyInteger('ab_test_sample_percentage')->default(20)->after('variant_b_template_id');
            $table->unsignedTinyInteger('ab_test_duration_hours')->default(4)->after('ab_test_sample_percentage');
            $table->string('ab_winning_metric', 20)->default('open_rate')->after('ab_test_duration_hours');
            $table->string('ab_winner_variant', 10)->nullable()->after('ab_winning_metric');
            $table->timestamp('ab_test_evaluated_at')->nullable()->after('ab_winner_variant');
        });

        // 9. Add variant column to Campaign Recipients
        Schema::table($recipientsTable, function (Blueprint $table): void {
            $table->string('variant', 10)->nullable()->after('status');
        });

        // 10. Add UTM Attribution fields to Form Submissions
        Schema::table($submissionsTable, function (Blueprint $table): void {
            $table->string('utm_source')->nullable()->after('form_data');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_term')->nullable()->after('utm_campaign');
            $table->string('utm_content')->nullable()->after('utm_term');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('focal-core.tables.contacts', 'focal_contacts');
        $campaignsTable = config('focal-marketing.tables.campaigns', 'focal_marketing_campaigns');
        $recipientsTable = config('focal-marketing.tables.recipients', 'focal_marketing_campaign_recipients');
        $submissionsTable = config('focal-marketing.tables.form_submissions', 'focal_marketing_form_submissions');

        $rulesTable = config('focal-marketing.tables.scoring_rules', 'focal_marketing_lead_scoring_rules');
        $scoreLogsTable = config('focal-marketing.tables.score_logs', 'focal_marketing_lead_score_logs');
        $workflowsTable = config('focal-marketing.tables.workflows', 'focal_marketing_workflows');
        $stepsTable = config('focal-marketing.tables.workflow_steps', 'focal_marketing_workflow_steps');
        $enrollmentsTable = config('focal-marketing.tables.workflow_enrollments', 'focal_marketing_workflow_enrollments');
        $workflowLogsTable = config('focal-marketing.tables.workflow_logs', 'focal_marketing_workflow_logs');

        Schema::table($submissionsTable, function (Blueprint $table): void {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']);
        });

        Schema::table($recipientsTable, function (Blueprint $table): void {
            $table->dropColumn(['variant']);
        });

        Schema::table($campaignsTable, function (Blueprint $table): void {
            $table->dropForeign(['crm_list_id']);
            $table->dropForeign(['variant_b_template_id']);
            $table->dropColumn([
                'crm_list_id',
                'is_ab_test',
                'variant_b_subject',
                'variant_b_template_id',
                'ab_test_sample_percentage',
                'ab_test_duration_hours',
                'ab_winning_metric',
                'ab_winner_variant',
                'ab_test_evaluated_at',
            ]);
        });

        Schema::dropIfExists($workflowLogsTable);
        Schema::dropIfExists($enrollmentsTable);
        Schema::dropIfExists($stepsTable);
        Schema::dropIfExists($workflowsTable);
        Schema::dropIfExists($scoreLogsTable);
        Schema::dropIfExists($rulesTable);

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn(['lead_score', 'lead_score_updated_at']);
        });
    }
};

<?php

declare(strict_types=1);

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
        $templatesTable = config('odden-marketing.tables.templates', 'odden_marketing_templates');
        $formsTable = config('odden-marketing.tables.forms', 'odden_marketing_forms');
        $submissionsTable = config('odden-marketing.tables.form_submissions', 'odden_marketing_form_submissions');
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $recipientsTable = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');
        $subscriptionsTable = config('odden-marketing.tables.subscriptions', 'odden_marketing_subscriptions');

        if (! Schema::hasTable($templatesTable)) {
            Schema::create($templatesTable, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('subject');
                $table->string('preview_text')->nullable();
                $table->longText('body_html');
                $table->text('body_text')->nullable();
                $table->string('category')->default('general');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($formsTable)) {
            Schema::create($formsTable, function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->json('fields_schema');
                $table->string('submit_button_text')->default('Submit');
                $table->text('success_message')->nullable();
                $table->string('redirect_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('submissions_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($submissionsTable)) {
            Schema::create($submissionsTable, function (Blueprint $table) use ($formsTable): void {
                $table->id();
                $table->foreignId('form_id')->constrained($formsTable)->cascadeOnDelete();
                $table->foreignId('contact_id')->nullable()->constrained('odden_contacts')->nullOnDelete();
                $table->json('form_data');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($campaignsTable)) {
            Schema::create($campaignsTable, function (Blueprint $table) use ($templatesTable): void {
                $table->id();
                $table->string('name');
                $table->string('subject');
                $table->string('preview_text')->nullable();
                $table->string('sender_name');
                $table->string('sender_email');
                $table->string('reply_to_email')->nullable();
                $table->foreignId('template_id')->nullable()->constrained($templatesTable)->nullOnDelete();
                $table->foreignId('list_id')->nullable()->constrained('odden_lists')->nullOnDelete();
                $table->string('status')->default('draft');
                $table->string('type')->default('regular');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->integer('total_recipients')->default(0);
                $table->integer('delivered_count')->default(0);
                $table->integer('opens_count')->default(0);
                $table->integer('unique_opens_count')->default(0);
                $table->integer('clicks_count')->default(0);
                $table->integer('unique_clicks_count')->default(0);
                $table->integer('bounces_count')->default(0);
                $table->integer('unsubscribes_count')->default(0);
                $table->json('properties')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($recipientsTable)) {
            Schema::create($recipientsTable, function (Blueprint $table) use ($campaignsTable): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained($campaignsTable)->cascadeOnDelete();
                $table->foreignId('contact_id')->nullable()->constrained('odden_contacts')->nullOnDelete();
                $table->string('email');
                $table->string('status')->default('pending');
                $table->string('tracking_token', 64)->unique();
                $table->string('unsubscribe_token', 64)->unique();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamps();

                $table->index(['campaign_id', 'status']);
            });
        }

        if (! Schema::hasTable($subscriptionsTable)) {
            Schema::create($subscriptionsTable, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('contact_id')->nullable()->constrained('odden_contacts')->nullOnDelete();
                $table->string('email')->unique();
                $table->string('status')->default('subscribed');
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $subscriptionsTable = config('odden-marketing.tables.subscriptions', 'odden_marketing_subscriptions');
        $recipientsTable = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $submissionsTable = config('odden-marketing.tables.form_submissions', 'odden_marketing_form_submissions');
        $formsTable = config('odden-marketing.tables.forms', 'odden_marketing_forms');
        $templatesTable = config('odden-marketing.tables.templates', 'odden_marketing_templates');

        Schema::dropIfExists($subscriptionsTable);
        Schema::dropIfExists($recipientsTable);
        Schema::dropIfExists($campaignsTable);
        Schema::dropIfExists($submissionsTable);
        Schema::dropIfExists($formsTable);
        Schema::dropIfExists($templatesTable);
    }
};

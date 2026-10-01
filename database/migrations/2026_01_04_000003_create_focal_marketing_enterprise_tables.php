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
        $formsTable = config('focal-marketing.tables.forms', 'focal_marketing_forms');
        $campaignsTable = config('focal-marketing.tables.campaigns', 'focal_marketing_campaigns');
        $recipientsTable = config('focal-marketing.tables.recipients', 'focal_marketing_campaign_recipients');

        $sessionsTable = config('focal-marketing.tables.visitor_sessions', 'focal_marketing_visitor_sessions');
        $pageViewsTable = config('focal-marketing.tables.page_views', 'focal_marketing_page_views');
        $landingPagesTable = config('focal-marketing.tables.landing_pages', 'focal_marketing_landing_pages');
        $espEventsTable = config('focal-marketing.tables.esp_events', 'focal_marketing_esp_events');

        // 1. Add SMS Consent to Contacts
        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->boolean('sms_consent')->default(false)->after('lead_score_updated_at');
            $table->timestamp('sms_consent_at')->nullable()->after('sms_consent');
        });

        // 2. Visitor Sessions (Web Inbound Tracking)
        Schema::create($sessionsTable, function (Blueprint $table) use ($contactsTable): void {
            $table->id();
            $table->string('visitor_token', 64)->unique();
            $table->foreignId('contact_id')->nullable()->constrained($contactsTable)->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();
        });

        // 3. Page Views Table
        Schema::create($pageViewsTable, function (Blueprint $table) use ($sessionsTable, $contactsTable): void {
            $table->id();
            $table->foreignId('session_id')->constrained($sessionsTable)->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained($contactsTable)->nullOnDelete();
            $table->text('url');
            $table->string('path', 255)->index();
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // 4. Hosted Landing Pages
        Schema::create($landingPagesTable, function (Blueprint $table) use ($formsTable): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('headline')->nullable();
            $table->string('subheadline')->nullable();
            $table->longText('body_content')->nullable();
            $table->foreignId('form_id')->nullable()->constrained($formsTable)->nullOnDelete();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('og_image_url')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignIdFor(UserModel::className(), 'created_by_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // 5. ESP Inbound Deliverability Events (Bounces & Complaints)
        Schema::create($espEventsTable, function (Blueprint $table) use ($campaignsTable, $recipientsTable): void {
            $table->id();
            $table->string('provider', 30)->index(); // ses, mailgun, postmark, resend, sendgrid, generic
            $table->string('event_type', 30)->index(); // bounce, complaint, delivered, opened, clicked, unsubscribed
            $table->string('email')->index();
            $table->foreignId('campaign_id')->nullable()->constrained($campaignsTable)->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained($recipientsTable)->nullOnDelete();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('focal-core.tables.contacts', 'focal_contacts');
        $sessionsTable = config('focal-marketing.tables.visitor_sessions', 'focal_marketing_visitor_sessions');
        $pageViewsTable = config('focal-marketing.tables.page_views', 'focal_marketing_page_views');
        $landingPagesTable = config('focal-marketing.tables.landing_pages', 'focal_marketing_landing_pages');
        $espEventsTable = config('focal-marketing.tables.esp_events', 'focal_marketing_esp_events');

        Schema::dropIfExists($espEventsTable);
        Schema::dropIfExists($landingPagesTable);
        Schema::dropIfExists($pageViewsTable);
        Schema::dropIfExists($sessionsTable);

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn(['sms_consent', 'sms_consent_at']);
        });
    }
};

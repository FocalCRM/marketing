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
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        // 1. Add Budget & Costs to Campaigns
        Schema::table($campaignsTable, function (Blueprint $table): void {
            $table->decimal('budget', 12, 2)->nullable()->after('type');
            $table->decimal('actual_cost', 12, 2)->default(0.00)->after('budget');
            $table->string('topic', 50)->nullable()->after('actual_cost');
        });

        // 2. Add Verification, Preferences, and Frequency Tracking to Contacts
        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->timestamp('marketing_email_verified_at')->nullable()->after('sms_consent_at');
            $table->string('marketing_verification_token', 64)->nullable()->index()->after('marketing_email_verified_at');
            $table->json('marketing_topics')->nullable()->after('marketing_verification_token');
            $table->timestamp('last_marketing_email_sent_at')->nullable()->after('marketing_topics');
        });

        // 3. Lead Score Decay Audit Logs
        Schema::create('odden_marketing_lead_decay_logs', function (Blueprint $table) use ($contactsTable): void {
            $table->id();
            $table->foreignId('contact_id')->constrained($contactsTable)->cascadeOnDelete();
            $table->integer('score_before');
            $table->integer('score_after');
            $table->integer('score_decayed');
            $table->unsignedInteger('days_inactive');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        Schema::dropIfExists('odden_marketing_lead_decay_logs');

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn([
                'marketing_email_verified_at',
                'marketing_verification_token',
                'marketing_topics',
                'last_marketing_email_sent_at',
            ]);
        });

        Schema::table($campaignsTable, function (Blueprint $table): void {
            $table->dropColumn(['budget', 'actual_cost', 'topic']);
        });
    }
};

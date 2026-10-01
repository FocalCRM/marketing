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
        // 1. Omnichannel SMS & Mobile Messaging Table
        Schema::create('focal_marketing_sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('focal_contacts')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('focal_marketing_campaigns')->nullOnDelete();
            $table->string('phone_number');
            $table->text('message_body');
            $table->string('status')->default('sent'); // queued, sent, delivered, failed
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
            $table->index('created_at');
        });

        // 2. Net Promoter Score (NPS) Surveys Table
        Schema::create('focal_marketing_nps_surveys', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. NPS Customer Responses & Sentiment Table
        Schema::create('focal_marketing_nps_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('focal_marketing_nps_surveys')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('focal_contacts')->nullOnDelete();
            $table->unsignedTinyInteger('score'); // 0 to 10
            $table->string('category'); // promoter, passive, detractor
            $table->text('feedback')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'category']);
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('focal_marketing_nps_responses');
        Schema::dropIfExists('focal_marketing_nps_surveys');
        Schema::dropIfExists('focal_marketing_sms_messages');
    }
};

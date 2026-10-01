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
        // 1. Marketing Digital Assets / Lead Magnets Table
        Schema::create('focal_marketing_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('asset_type')->default('whitepaper'); // whitepaper, case_study, guide, template, spreadsheet, report
            $table->string('file_path')->nullable();
            $table->string('external_url')->nullable();
            $table->unsignedInteger('file_size_kb')->nullable();
            $table->boolean('is_gated')->default(true);
            $table->unsignedInteger('lead_score_points')->default(15);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedInteger('unique_leads_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('asset_type');
            $table->index('is_active');
        });

        // 2. Marketing Asset Downloads Table
        Schema::create('focal_marketing_asset_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('focal_marketing_assets')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('focal_contacts')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('download_token', 64)->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamps();

            $table->index(['asset_id', 'contact_id']);
            $table->index('downloaded_at');
        });

        // 3. Marketing Events & Webinars Table
        Schema::create('focal_marketing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('event_type')->default('webinar'); // webinar, in_person, workshop, round_table
            $table->string('status')->default('scheduled'); // draft, scheduled, live, completed, cancelled
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->string('virtual_meeting_url')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('registrations_count')->default(0);
            $table->unsignedInteger('attendees_count')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index('starts_at');
        });

        // 4. Marketing Event Registrations Table
        Schema::create('focal_marketing_event_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('focal_marketing_events')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('focal_contacts')->cascadeOnDelete();
            $table->string('status')->default('registered'); // registered, attended, no_show, cancelled
            $table->timestamp('registered_at');
            $table->timestamp('attended_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'contact_id']);
            $table->index(['event_id', 'status']);
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('focal_marketing_event_registrations');
        Schema::dropIfExists('focal_marketing_events');
        Schema::dropIfExists('focal_marketing_asset_downloads');
        Schema::dropIfExists('focal_marketing_assets');
    }
};

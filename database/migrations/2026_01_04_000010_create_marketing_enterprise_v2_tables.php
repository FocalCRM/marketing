<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $campaignsTable = config('focal-marketing.tables.campaigns', 'focal_marketing_campaigns');
        $eventsTable = config('focal-marketing.tables.custom_behavioral_events', 'focal_custom_behavioral_events');
        $adSyncsTable = config('focal-marketing.tables.ad_audience_syncs', 'focal_ad_audience_syncs');
        $contactsTable = config('focal-core.tables.contacts', 'focal_contacts');
        $companiesTable = config('focal-core.tables.companies', 'focal_companies');
        $listsTable = config('focal-core.tables.lists', 'focal_lists');

        if (Schema::hasTable($campaignsTable)) {
            Schema::table($campaignsTable, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'send_in_recipient_timezone')) {
                    $table->boolean('send_in_recipient_timezone')->default(false)->after('scheduled_at');
                }
                if (! Schema::hasColumn($table->getTable(), 'recipient_send_hour')) {
                    $table->unsignedTinyInteger('recipient_send_hour')->default(9)->after('send_in_recipient_timezone');
                }
            });
        }

        if (! Schema::hasTable($eventsTable)) {
            Schema::create($eventsTable, function (Blueprint $table) use ($contactsTable, $companiesTable): void {
                $table->id();
                $table->foreignId('contact_id')->nullable()->constrained($contactsTable)->nullOnDelete();
                $table->foreignId('company_id')->nullable()->constrained($companiesTable)->nullOnDelete();
                $table->string('event_name')->index();
                $table->json('properties')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($adSyncsTable)) {
            Schema::create($adSyncsTable, function (Blueprint $table) use ($listsTable): void {
                $table->id();
                $table->string('name');
                $table->string('platform')->index(); // 'linkedin', 'google', 'meta'
                $table->foreignId('list_id')->constrained($listsTable)->cascadeOnDelete();
                $table->string('audience_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('records_count')->default(0);
                $table->timestamp('last_synced_at')->nullable();
                $table->json('config')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $campaignsTable = config('focal-marketing.tables.campaigns', 'focal_marketing_campaigns');
        $eventsTable = config('focal-marketing.tables.custom_behavioral_events', 'focal_custom_behavioral_events');
        $adSyncsTable = config('focal-marketing.tables.ad_audience_syncs', 'focal_ad_audience_syncs');

        Schema::dropIfExists($adSyncsTable);
        Schema::dropIfExists($eventsTable);

        if (Schema::hasTable($campaignsTable)) {
            Schema::table($campaignsTable, function (Blueprint $table): void {
                if (Schema::hasColumn($table->getTable(), 'recipient_send_hour')) {
                    $table->dropColumn('recipient_send_hour');
                }
                if (Schema::hasColumn($table->getTable(), 'send_in_recipient_timezone')) {
                    $table->dropColumn('send_in_recipient_timezone');
                }
            });
        }
    }
};

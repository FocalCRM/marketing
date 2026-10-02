<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $eventsTable = config('odden-marketing.tables.custom_behavioral_events', 'odden_custom_behavioral_events');
        $adSyncsTable = config('odden-marketing.tables.ad_audience_syncs', 'odden_ad_audience_syncs');
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');
        $companiesTable = config('odden-core.tables.companies', 'odden_companies');
        $listsTable = config('odden-core.tables.lists', 'odden_lists');

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
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $eventsTable = config('odden-marketing.tables.custom_behavioral_events', 'odden_custom_behavioral_events');
        $adSyncsTable = config('odden-marketing.tables.ad_audience_syncs', 'odden_ad_audience_syncs');

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

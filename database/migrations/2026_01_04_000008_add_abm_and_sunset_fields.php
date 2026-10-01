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
        // 1. ABM & Intent Scoring on Companies
        Schema::table('focal_companies', function (Blueprint $table): void {
            $table->string('account_tier', 20)->nullable()->after('industry'); // tier_1, tier_2, tier_3
            $table->unsignedInteger('intent_score')->default(0)->after('account_tier');
            $table->boolean('intent_surge')->default(false)->after('intent_score');
            $table->unsignedInteger('buying_committee_size')->default(0)->after('intent_surge');
            $table->timestamp('last_intent_activity_at')->nullable()->after('buying_committee_size');

            $table->index(['account_tier', 'intent_score']);
        });

        // 2. Sunset Policy & List Hygiene on Contacts
        Schema::table('focal_contacts', function (Blueprint $table): void {
            $table->boolean('is_unengaged')->default(false)->after('last_marketing_email_sent_at');
            $table->timestamp('unengaged_since')->nullable()->after('is_unengaged');
            $table->string('sunset_stage', 30)->nullable()->after('unengaged_since'); // active, flagged, reengagement_sent, suppressed

            $table->index(['is_unengaged', 'sunset_stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('focal_contacts', function (Blueprint $table): void {
            $table->dropIndex(['is_unengaged', 'sunset_stage']);
            $table->dropColumn(['is_unengaged', 'unengaged_since', 'sunset_stage']);
        });

        Schema::table('focal_companies', function (Blueprint $table): void {
            $table->dropIndex(['account_tier', 'intent_score']);
            $table->dropColumn([
                'account_tier',
                'intent_score',
                'intent_surge',
                'buying_committee_size',
                'last_intent_activity_at',
            ]);
        });
    }
};

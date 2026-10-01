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
        // ABM & intent scoring columns on companies are owned by focalcrm/core.

        // Sunset Policy & List Hygiene on Contacts
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
    }
};

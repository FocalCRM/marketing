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
        $table = config('focal-marketing.tables.suppressions', 'focal_marketing_suppressions');

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('email')->index();
                $table->string('reason')->default('hard_bounce')->index(); // hard_bounce, spam_complaint, manual_blocklist, unsubscribe
                $table->string('source')->nullable(); // esp_webhook, manual, campaign_bounce
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('focal-marketing.tables.suppressions', 'focal_marketing_suppressions');
        Schema::dropIfExists($table);
    }
};

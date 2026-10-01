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
        Schema::table('focal_marketing_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('target_leads')->nullable()->after('actual_cost');
            $table->decimal('target_pipeline', 12, 2)->nullable()->after('target_leads');
            $table->decimal('target_revenue', 12, 2)->nullable()->after('target_pipeline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('focal_marketing_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['target_leads', 'target_pipeline', 'target_revenue']);
        });
    }
};

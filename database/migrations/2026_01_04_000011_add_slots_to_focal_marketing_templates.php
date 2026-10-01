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
        $templatesTable = config('focal-marketing.tables.templates', 'focal_marketing_templates');

        if (Schema::hasTable($templatesTable) && ! Schema::hasColumn($templatesTable, 'slots')) {
            Schema::table($templatesTable, function (Blueprint $table): void {
                $table->json('slots')->nullable()->after('preview_text');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $templatesTable = config('focal-marketing.tables.templates', 'focal_marketing_templates');

        if (Schema::hasTable($templatesTable) && Schema::hasColumn($templatesTable, 'slots')) {
            Schema::table($templatesTable, function (Blueprint $table): void {
                $table->dropColumn('slots');
            });
        }
    }
};

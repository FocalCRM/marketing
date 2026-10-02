<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $formsTable = config('odden-marketing.tables.forms', 'odden_marketing_forms');

        if (Schema::hasTable($formsTable)) {
            Schema::table($formsTable, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'progressive_profiling_enabled')) {
                    $table->boolean('progressive_profiling_enabled')->default(false)->after('fields_schema');
                }
                if (! Schema::hasColumn($table->getTable(), 'progressive_fields')) {
                    $table->json('progressive_fields')->nullable()->after('progressive_profiling_enabled');
                }
            });
        }
    }

    public function down(): void
    {
        $formsTable = config('odden-marketing.tables.forms', 'odden_marketing_forms');

        if (Schema::hasTable($formsTable)) {
            Schema::table($formsTable, function (Blueprint $table): void {
                if (Schema::hasColumn($table->getTable(), 'progressive_fields')) {
                    $table->dropColumn('progressive_fields');
                }
                if (Schema::hasColumn($table->getTable(), 'progressive_profiling_enabled')) {
                    $table->dropColumn('progressive_profiling_enabled');
                }
            });
        }
    }
};

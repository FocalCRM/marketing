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
        $table = config('focal-marketing.tables.templates', 'focal_marketing_templates');

        if (Schema::hasTable($table)) {
            Schema::table($table, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'theme')) {
                    $table->json('theme')->nullable()->after('slots');
                }
                if (! Schema::hasColumn($table->getTable(), 'subject_variant_b')) {
                    $table->string('subject_variant_b')->nullable()->after('subject');
                }
                if (! Schema::hasColumn($table->getTable(), 'preview_text_variant_b')) {
                    $table->string('preview_text_variant_b')->nullable()->after('preview_text');
                }
                if (! Schema::hasColumn($table->getTable(), 'slots_variant_b')) {
                    $table->json('slots_variant_b')->nullable()->after('theme');
                }
                if (! Schema::hasColumn($table->getTable(), 'ab_split_percentage')) {
                    $table->unsignedTinyInteger('ab_split_percentage')->default(50)->after('slots_variant_b');
                }
                if (! Schema::hasColumn($table->getTable(), 'body_html_variant_b')) {
                    $table->longText('body_html_variant_b')->nullable()->after('body_html');
                }
                if (! Schema::hasColumn($table->getTable(), 'body_text_variant_b')) {
                    $table->longText('body_text_variant_b')->nullable()->after('body_text');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('focal-marketing.tables.templates', 'focal_marketing_templates');

        if (Schema::hasTable($table)) {
            Schema::table($table, function (Blueprint $table): void {
                $columns = ['theme', 'subject_variant_b', 'preview_text_variant_b', 'slots_variant_b', 'ab_split_percentage', 'body_html_variant_b', 'body_text_variant_b'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

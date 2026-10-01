<?php

declare(strict_types=1);

use Focal\Core\Support\UserModel;
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
        $revisionsTable = 'focal_marketing_template_revisions';

        if (Schema::hasTable($templatesTable)) {
            Schema::table($templatesTable, function (Blueprint $table) use ($templatesTable): void {
                if (! Schema::hasColumn($templatesTable, 'slug')) {
                    $table->string('slug')->nullable()->after('name')->index();
                }
                if (! Schema::hasColumn($templatesTable, 'ab_winner_variant')) {
                    $table->string('ab_winner_variant', 10)->nullable()->after('ab_split_percentage');
                }
                if (! Schema::hasColumn($templatesTable, 'ab_completed_at')) {
                    $table->timestamp('ab_completed_at')->nullable()->after('ab_winner_variant');
                }
            });
        }

        if (! Schema::hasTable($revisionsTable)) {
            Schema::create($revisionsTable, function (Blueprint $table) use ($templatesTable): void {
                $table->id();
                $table->foreignId('template_id')->constrained($templatesTable)->cascadeOnDelete();
                $table->unsignedInteger('version_number')->default(1);
                $table->string('name');
                $table->string('subject');
                $table->string('subject_variant_b')->nullable();
                $table->string('preview_text')->nullable();
                $table->string('preview_text_variant_b')->nullable();
                $table->json('slots')->nullable();
                $table->json('slots_variant_b')->nullable();
                $table->json('theme')->nullable();
                $table->longText('body_html');
                $table->longText('body_text')->nullable();
                $table->string('notes')->nullable();
                $table->foreignIdFor(UserModel::className(), 'created_by')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->index(['template_id', 'version_number']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('focal_marketing_template_revisions');

        $templatesTable = config('focal-marketing.tables.templates', 'focal_marketing_templates');
        if (Schema::hasTable($templatesTable)) {
            Schema::table($templatesTable, function (Blueprint $table) use ($templatesTable): void {
                $columns = ['slug', 'ab_winner_variant', 'ab_completed_at'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn($templatesTable, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

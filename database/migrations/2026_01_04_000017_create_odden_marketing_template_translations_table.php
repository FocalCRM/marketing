<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odden_marketing_template_translations')) {
            Schema::create('odden_marketing_template_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('odden_marketing_templates')->cascadeOnDelete();
                $table->string('locale', 10); // e.g. 'es', 'de', 'fr', 'ja'
                $table->string('subject');
                $table->string('subject_variant_b')->nullable();
                $table->string('preview_text')->nullable();
                $table->string('preview_text_variant_b')->nullable();
                $table->longText('body_html')->nullable();
                $table->longText('body_text')->nullable();
                $table->json('slots')->nullable();
                $table->timestamps();

                $table->unique(['template_id', 'locale']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odden_marketing_template_translations');
    }
};

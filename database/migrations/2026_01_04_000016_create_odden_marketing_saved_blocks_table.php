<?php

declare(strict_types=1);

use Odden\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odden_marketing_saved_blocks')) {
            Schema::create('odden_marketing_saved_blocks', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('category')->default('general');
                $table->string('slot_type');
                $table->json('slot_data');
                $table->foreignIdFor(UserModel::className(), 'created_by')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odden_marketing_saved_blocks');
    }
};

<?php

declare(strict_types=1);

use Focal\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('focal_marketing_saved_blocks')) {
            Schema::create('focal_marketing_saved_blocks', function (Blueprint $table): void {
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
        Schema::dropIfExists('focal_marketing_saved_blocks');
    }
};

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
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $recipientsTable = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');
        $topicsTable = config('odden-marketing.tables.subscription_topics', 'odden_marketing_subscription_topics');
        $contactTopicsTable = config('odden-marketing.tables.contact_topics', 'odden_marketing_contact_topics');

        // 1. Subscription Topics Table
        if (! Schema::hasTable($topicsTable)) {
            Schema::create($topicsTable, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_default')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // 2. Contact Topics Pivot Table
        if (! Schema::hasTable($contactTopicsTable)) {
            Schema::create($contactTopicsTable, function (Blueprint $table) use ($topicsTable): void {
                $table->id();
                $table->string('email')->index();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->foreignId('topic_id')->constrained($topicsTable)->cascadeOnDelete();
                $table->boolean('is_subscribed')->default(true);
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();

                $table->unique(['email', 'topic_id']);
            });
        }

        // 3. Update Campaigns Table with UTM, Timezone, Budget & Topic columns
        if (Schema::hasTable($campaignsTable)) {
            Schema::table($campaignsTable, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'utm_auto_tag')) {
                    $table->boolean('utm_auto_tag')->default(true);
                }
                if (! Schema::hasColumn($table->getTable(), 'utm_campaign')) {
                    $table->string('utm_campaign')->nullable();
                }
                if (! Schema::hasColumn($table->getTable(), 'send_by_timezone')) {
                    $table->boolean('send_by_timezone')->default(false);
                }
                if (! Schema::hasColumn($table->getTable(), 'scheduled_local_time')) {
                    $table->string('scheduled_local_time', 8)->nullable();
                }
                if (! Schema::hasColumn($table->getTable(), 'use_sto')) {
                    $table->boolean('use_sto')->default(false);
                }
                if (! Schema::hasColumn($table->getTable(), 'budget')) {
                    $table->decimal('budget', 12, 2)->default(0.00);
                }
                if (! Schema::hasColumn($table->getTable(), 'actual_spend')) {
                    $table->decimal('actual_spend', 12, 2)->default(0.00);
                }
                if (! Schema::hasColumn($table->getTable(), 'topic_id')) {
                    $table->unsignedBigInteger('topic_id')->nullable()->index();
                }
            });
        }

        // 4. Update Campaign Recipients Table with scheduled_send_at
        if (Schema::hasTable($recipientsTable)) {
            Schema::table($recipientsTable, function (Blueprint $table): void {
                if (! Schema::hasColumn($table->getTable(), 'scheduled_send_at')) {
                    $table->timestamp('scheduled_send_at')->nullable()->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $campaignsTable = config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
        $recipientsTable = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');
        $topicsTable = config('odden-marketing.tables.subscription_topics', 'odden_marketing_subscription_topics');
        $contactTopicsTable = config('odden-marketing.tables.contact_topics', 'odden_marketing_contact_topics');

        Schema::dropIfExists($contactTopicsTable);
        Schema::dropIfExists($topicsTable);

        if (Schema::hasTable($recipientsTable) && Schema::hasColumn($recipientsTable, 'scheduled_send_at')) {
            Schema::table($recipientsTable, function (Blueprint $table): void {
                $table->dropColumn('scheduled_send_at');
            });
        }

        if (Schema::hasTable($campaignsTable)) {
            Schema::table($campaignsTable, function (Blueprint $table): void {
                $columns = ['utm_auto_tag', 'utm_campaign', 'send_by_timezone', 'scheduled_local_time', 'use_sto', 'budget', 'actual_spend', 'topic_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

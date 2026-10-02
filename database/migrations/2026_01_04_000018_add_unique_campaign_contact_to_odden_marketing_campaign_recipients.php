<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One recipient row per campaign and contact, so dispatching a campaign twice
 * cannot create (or send to) the same contact twice.
 */
return new class extends Migration
{
    private const INDEX = 'odden_mkt_recipients_campaign_contact_unique';

    public function up(): void
    {
        $table = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');

        if (! Schema::hasTable($table) || Schema::hasIndex($table, self::INDEX)) {
            return;
        }

        // Earlier versions created a new row on every dispatch. Some installs delivered
        // campaign mail through a custom compiler, so a duplicate may hold the real
        // engagement: keep the most engaged row for each campaign and contact attached to it.
        $espEventsTable = config('odden-marketing.tables.esp_events', 'odden_marketing_esp_events');

        $groups = DB::table($table)
            ->whereNotNull('contact_id')
            ->groupBy('campaign_id', 'contact_id')
            ->havingRaw('COUNT(*) > 1')
            ->select('campaign_id', 'contact_id')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table($table)
                ->where('campaign_id', $group->campaign_id)
                ->where('contact_id', $group->contact_id)
                ->orderByRaw('CASE WHEN clicked_at IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN sent_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('id')
                ->get(['id', 'sent_at']);

            $keepId = $rows->shift()?->id;

            // A sent duplicate's unsubscribe and tracking links are in someone's inbox: detach
            // it from the contact (NULLs don't conflict with the unique index) so they keep
            // working. Unsent duplicates have no links out there and are deleted.
            $sentIds = $rows->whereNotNull('sent_at')->pluck('id')->all();
            $unsentIds = $rows->whereNull('sent_at')->pluck('id')->all();

            DB::table($table)->whereIn('id', $sentIds)->update(['contact_id' => null]);

            if (Schema::hasTable($espEventsTable)) {
                DB::table($espEventsTable)->whereIn('recipient_id', $unsentIds)->update(['recipient_id' => $keepId]);
            }

            DB::table($table)->whereIn('id', $unsentIds)->delete();
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unique(['campaign_id', 'contact_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        $table = config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');

        if (Schema::hasTable($table) && Schema::hasIndex($table, self::INDEX)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(self::INDEX);
            });
        }
    }
};

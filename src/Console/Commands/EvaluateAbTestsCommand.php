<?php

declare(strict_types=1);

namespace Odden\Marketing\Console\Commands;

use Odden\Marketing\Actions\EvaluateAbTestWinnerAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Models\Campaign;
use Illuminate\Console\Command;

class EvaluateAbTestsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:evaluate-ab-tests';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate pending A/B test campaigns whose duration has expired and deploy the winning variant';

    /**
     * Execute the console command.
     */
    public function handle(EvaluateAbTestWinnerAction $action): int
    {
        $this->info('Scanning for mature A/B test campaigns...');

        $campaigns = Campaign::query()
            ->where('is_ab_test', true)
            ->whereNull('ab_winner_variant')
            ->where('status', CampaignStatus::Sending->value)
            ->get();

        $evaluated = 0;

        foreach ($campaigns as $campaign) {
            $durationHours = $campaign->ab_test_duration_hours ?: 4;
            $matureAt = $campaign->sent_at?->copy()->addHours($durationHours);

            if ($matureAt === null || now()->isAfter($matureAt)) {
                $result = $action->execute($campaign);
                $this->info("Campaign #{$campaign->id} ('{$campaign->name}'): Variant {$result['winner']} won! Dispatched to {$result['remaining_sent']} remaining contacts.");
                $evaluated++;
            }
        }

        $this->info("Evaluated {$evaluated} A/B campaign(s).");

        return self::SUCCESS;
    }
}

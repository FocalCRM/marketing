<?php

declare(strict_types=1);

namespace Odden\Marketing\Console\Commands;

use Illuminate\Console\Command;
use Odden\Marketing\Actions\DecayInactiveLeadScoresAction;

class DecayLeadScoresCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:decay-lead-scores {--days=30 : Inactivity threshold in days} {--points=5 : Points to deduct per inactive period}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan contacts with active lead scores and apply inactivity time-decay degradation';

    /**
     * Execute the console command.
     */
    public function handle(DecayInactiveLeadScoresAction $action): int
    {
        $days = (int) $this->option('days');
        $points = (int) $this->option('points');

        $this->info("Scanning for contacts inactive for {$days}+ days...");

        $result = $action->execute($days, $points);

        $this->info("Decayed lead scores for {$result['decayed_contacts_count']} contact(s) (total {$result['total_points_decayed']} pts deducted).");

        return self::SUCCESS;
    }
}

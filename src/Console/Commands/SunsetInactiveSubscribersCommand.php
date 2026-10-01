<?php

declare(strict_types=1);

namespace Focal\Marketing\Console\Commands;

use Focal\Marketing\Actions\ProcessSubscriberSunsetPolicyAction;
use Illuminate\Console\Command;

class SunsetInactiveSubscribersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:sunset-subscribers
                            {--days=90 : Inactivity window in days without opens or clicks}
                            {--min-sends=3 : Minimum marketing emails contact must have received}
                            {--suppress : Automatically unsubscribe dormant subscribers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan subscribers for prolonged disengagement and apply sunset protection to safeguard sender reputation.';

    /**
     * Execute the console command.
     */
    public function handle(ProcessSubscriberSunsetPolicyAction $action): int
    {
        $days = (int) $this->option('days');
        $minSends = (int) $this->option('min-sends');
        $suppress = (bool) $this->option('suppress');

        $this->info("Scanning subscribers with {$days}+ days inactivity (min {$minSends} sends received)...");

        $results = $action->execute(
            inactivityDays: $days,
            minSendsReceived: $minSends,
            autoSuppress: $suppress
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Candidates Evaluated', $results['dormant_evaluated_count']],
                ['Dormant Subscribers Detected', $results['dormant_detected_count']],
                ['Auto-Suppressed (Unsubscribed)', $results['auto_suppressed_count']],
            ]
        );

        if ($suppress) {
            $this->info("Successfully auto-suppressed {$results['auto_suppressed_count']} dormant subscribers.");
        } else {
            $this->comment("Detected {$results['dormant_detected_count']} dormant subscribers. Run with --suppress to automatically opt them out.");
        }

        return self::SUCCESS;
    }
}

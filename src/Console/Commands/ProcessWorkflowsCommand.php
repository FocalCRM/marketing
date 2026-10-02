<?php

declare(strict_types=1);

namespace Odden\Marketing\Console\Commands;

use Illuminate\Console\Command;
use Odden\Marketing\Actions\ProcessDueWorkflowsAction;

class ProcessWorkflowsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:process-workflows';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan and process active marketing workflows and advance matured delay timers';

    /**
     * Execute the console command.
     */
    public function handle(ProcessDueWorkflowsAction $action): int
    {
        $this->info('Processing active marketing drip workflows...');

        $processed = $action->execute();

        $this->info("Advanced {$processed} workflow enrollment(s) successfully.");

        return self::SUCCESS;
    }
}

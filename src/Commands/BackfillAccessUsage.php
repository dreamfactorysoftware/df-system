<?php

namespace DreamFactory\Core\System\Commands;

use DreamFactory\Core\System\Components\AccessUsageRecorder;
use Illuminate\Console\Command;

/**
 * Seeds access_usage from agent_activity_ledger history. The create migration
 * already runs this once; re-run it any time, it only moves dates outward.
 */
class BackfillAccessUsage extends Command
{
    protected $signature = 'df:access-usage-backfill';

    protected $description = 'Seed access_usage last-used times from agent_activity_ledger history (df-agents installs)';

    public function handle(): int
    {
        $changed = AccessUsageRecorder::backfillFromLedger();
        $this->info("Backfilled {$changed} access_usage subject(s) from agent_activity_ledger.");

        return self::SUCCESS;
    }
}

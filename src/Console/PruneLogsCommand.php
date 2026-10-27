<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Console;

use Illuminate\Console\Command;
use IvoWmg\Borica\Logging\DatabaseLog;

/**
 * Deletes the old rows of `borica_logs`. The default keeps 13 months: a year of reconciliation with
 * the bank and a month to spare. Schedule it daily.
 */
final class PruneLogsCommand extends Command
{
    protected $signature = 'borica:prune-logs {--days=400 : keep this many days}';

    protected $description = 'Deletes the Borica log rows older than --days';

    public function handle(DatabaseLog $log): int
    {
        $deleted = $log->prune(max(30, (int) $this->option('days')));
        $this->info("{$deleted} rows deleted.");

        return self::SUCCESS;
    }
}

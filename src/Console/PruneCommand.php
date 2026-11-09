<?php

declare(strict_types=1);

namespace Atannex\Matrix\Console;

use Atannex\Matrix\Support\Connections;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'matrix:prune';

    protected $description = 'Delete raw engagement rows past their retention (totals are kept).';

    public function handle(): int
    {
        $class = config('matrix.models.click');
        $pruned = 0;

        // On every connection that holds engagement (Support\Connections).
        foreach (Connections::all() as $connection) {
            $pruned += (new $class)->setConnection($connection)->pruneAll();
        }

        $this->components->info("{$pruned} raw rows pruned.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Matrix\Console;

use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'matrix:prune';

    protected $description = 'Delete raw engagement rows past their retention (totals are kept).';

    public function handle(): int
    {
        return $this->call('model:prune', ['--model' => [config('matrix.models.click')]]);
    }
}

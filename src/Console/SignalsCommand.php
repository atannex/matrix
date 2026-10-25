<?php

declare(strict_types=1);

namespace Atannex\Matrix\Console;

use Atannex\Matrix\Matrix;
use Illuminate\Console\Command;

final class SignalsCommand extends Command
{
    protected $signature = 'matrix:signals';

    protected $description = 'List the engagement signals the matrix knows about.';

    public function handle(Matrix $matrix): int
    {
        $this->table(['Signal', 'Class', 'Table', 'Guests', 'Kind'], collect($matrix->signals())->map(fn ($signal) => [
            $signal->name(),
            $signal::class,
            $signal->table() ?? '— (counters only)',
            $signal->allowsGuests() ? 'yes' : 'no',
            $signal->interactive() ? 'interactive' : 'tracking',
        ])->values()->all());

        return self::SUCCESS;
    }
}

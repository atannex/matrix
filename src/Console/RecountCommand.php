<?php

declare(strict_types=1);

namespace Atannex\Matrix\Console;

use Atannex\Matrix\Support\Counters;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;

final class RecountCommand extends Command
{
    protected $signature = 'matrix:recount {--type= : Only items of this model class or morph alias}';

    protected $description = 'Recompute every item\'s engagement totals from the raw rows (fixes counter drift).';

    public function handle(Counters $counters): int
    {
        $type = $this->option('type');

        if (is_string($type) && class_exists($type)) {
            $type = array_search($type, Relation::morphMap(), true) ?: $type;
        }

        $count = $counters->rebuild($type ?: null);

        $this->components->info("Recounted {$count} item(s).");

        return self::SUCCESS;
    }
}

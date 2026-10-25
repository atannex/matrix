<?php

declare(strict_types=1);

namespace Atannex\Matrix\Console;

use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Matrix;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class StatsCommand extends Command
{
    protected $signature = 'matrix:stats {type : Model class or morph alias} {id : The item\'s key}';

    protected $description = 'Show an item\'s engagement totals and scores.';

    public function handle(Matrix $matrix): int
    {
        $class = Relation::getMorphedModel($this->argument('type')) ?? $this->argument('type');

        if (! class_exists($class) || ! is_a($class, Model::class, true) || ! is_a($class, Engageable::class, true)) {
            $this->components->error("[{$this->argument('type')}] is not an engageable model.");

            return self::FAILURE;
        }

        $item = $class::query()->find($this->argument('id'));

        if ($item === null) {
            $this->components->error('No such item.');

            return self::FAILURE;
        }

        $stats = $matrix->stats($item);
        $rows = collect($stats->all())->sortKeys()->map(fn (int $value, string $metric) => [$metric, number_format($value)])->values();

        $this->table(['Metric', 'Total'], $rows->all());
        $this->components->twoColumnDetail('Average rating', (string) $stats->averageRating());
        $this->components->twoColumnDetail('Bayesian rating', (string) $stats->bayesianRating());
        $this->components->twoColumnDetail('Approval (Wilson)', (string) $stats->approval());
        $this->components->twoColumnDetail('Engagement score', (string) $stats->score());

        return self::SUCCESS;
    }
}

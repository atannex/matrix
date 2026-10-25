<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Read;
use Illuminate\Database\Eloquent\Model;

/**
 * Engaged reading time reported by the page (only while the tab is visible
 * and the reader active). Counter-only: no raw rows.
 *
 * Metrics: reads (first report of a page view), read_seconds.
 */
class ReadSignal extends Signal
{
    public function name(): string
    {
        return 'read';
    }

    public function interactive(): bool
    {
        return false;
    }

    public function identity(): ?array
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }

    public function table(): ?string
    {
        return null;
    }

    public function record(Model $item, Actor $actor, int $seconds, bool $first = true): int
    {
        $seconds = min($seconds, (int) $this->config('max_seconds', 1800));

        if ($seconds < 1 || $actor->isBot() || ! $this->matrix()->guard($item, $this->name(), $actor)) {
            return 0;
        }

        $this->counters()->increment($item, ['read_seconds' => $seconds] + ($first ? ['reads' => 1] : []));
        $this->matrix()->dispatch(new Read($item, $actor, context: ['seconds' => $seconds, 'first' => $first]));

        return $seconds;
    }
}

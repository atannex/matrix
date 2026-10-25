<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Signals\Registry;
use Atannex\Matrix\Signals\Signal;
use Atannex\Matrix\Stats;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Running totals (one row per item and metric) and daily rollups (one row
 * per item, day and metric). Narrow rows mean any signal can add metrics
 * without a migration.
 *
 * High-volume signals add to the totals atomically; toggles recount the
 * item from the raw rows (see Signal::recount), so they never drift.
 */
final class Counters
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Registry $signals,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Add to the item's totals and to today's rollup.
     *
     * @param  array<string, int>  $totals  metric => amount
     * @param  array<string, int>|null  $daily  metric => amount (null: same as $totals)
     */
    public function increment(Model $item, array $totals, ?array $daily = null): void
    {
        [$type, $id] = [$item->getMorphClass(), $item->getKey()];

        $this->incrementKey($type, $id, $totals, $daily ?? $totals);
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $daily
     */
    public function incrementKey(string $type, int|string $id, array $totals, array $daily = []): void
    {
        $now = now();
        $key = ['engageable_type' => $type, 'engageable_id' => $id];

        foreach (array_filter($totals) as $metric => $amount) {
            $this->add($this->table('counter'), $key + ['metric' => $this->metric($metric)], (int) $amount, $now);
        }

        foreach (array_filter($daily) as $metric => $amount) {
            $this->add($this->table('daily'), $key + ['day' => $now->toDateString(), 'metric' => $this->metric($metric)], (int) $amount, $now);
        }
    }

    /**
     * Recompute the item's totals from the raw rows. Metrics only kept as
     * counters (reading time, clicks past retention) are left alone.
     */
    public function recount(Model $item): Stats
    {
        $this->recountKey($item->getMorphClass(), $item->getKey());

        return $this->for($item);
    }

    public function recountKey(string $type, int|string $id): void
    {
        $values = [];

        foreach ($this->signals->all() as $signal) {
            $values += $signal->recount($type, $id);
        }

        $this->connection()->transaction(function () use ($type, $id, $values) {
            $stale = $this->query('counter')
                ->where('engageable_type', $type)
                ->where('engageable_id', $id)
                ->pluck('metric')
                ->filter(fn (string $metric) => ! array_key_exists($metric, $values) && $this->signals->recountable($metric));

            if ($stale->isNotEmpty()) {
                $this->query('counter')
                    ->where('engageable_type', $type)
                    ->where('engageable_id', $id)
                    ->whereIn('metric', $stale->all())
                    ->delete();
            }

            $this->put($type, $id, $values);
        });
    }

    /**
     * Recount just one signal's metrics for an item (after a toggle).
     */
    public function refresh(string $type, int|string $id, Signal $signal): void
    {
        $values = $signal->recount($type, $id);

        $this->connection()->transaction(function () use ($type, $id, $values, $signal) {
            $stale = $this->query('counter')
                ->where('engageable_type', $type)
                ->where('engageable_id', $id)
                ->pluck('metric')
                ->filter(fn (string $metric) => ! array_key_exists($metric, $values) && $signal->owns($metric));

            if ($stale->isNotEmpty()) {
                $this->query('counter')
                    ->where('engageable_type', $type)
                    ->where('engageable_id', $id)
                    ->whereIn('metric', $stale->all())
                    ->delete();
            }

            $this->put($type, $id, $values);
        });
    }

    /**
     * Overwrite totals.
     *
     * @param  array<string, int>  $values  metric => value
     */
    public function put(string $type, int|string $id, array $values): void
    {
        if ($values === []) {
            return;
        }

        $now = now();

        $rows = array_map(fn (string $metric, int $value) => [
            'engageable_type' => $type,
            'engageable_id' => $id,
            'metric' => $this->metric($metric),
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($values), array_map('intval', $values));

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->query('counter')->upsert($chunk, ['engageable_type', 'engageable_id', 'metric'], ['value', 'updated_at']);
        }
    }

    /**
     * Recount every item that has engagement (fixes any drift).
     *
     * @return int items recounted
     */
    public function rebuild(?string $type = null, ?callable $progress = null): int
    {
        $items = collect();

        foreach ($this->signals->all() as $signal) {
            $items = $items->merge($signal->subjects($type));
        }

        $items = $items->unique(fn (array $item) => $item['type'].'|'.$item['id'])->values();

        foreach ($items as $item) {
            $this->recountKey($item['type'], $item['id']);

            if ($progress) {
                $progress($item);
            }
        }

        return $items->count();
    }

    /**
     * Remove an item's totals and rollups (it was deleted).
     */
    public function forget(string $type, int|string $id): void
    {
        foreach (['counter', 'daily'] as $table) {
            $this->query($table)->where('engageable_type', $type)->where('engageable_id', $id)->delete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The item's totals (all zero when nothing is recorded yet).
     */
    public function for(Model $item): Stats
    {
        if ($item->relationLoaded('matrixCounters')) {
            return Stats::fromRows($item->getRelation('matrixCounters'), $item);
        }

        $rows = $this->query('counter')
            ->where('engageable_type', $item->getMorphClass())
            ->where('engageable_id', $item->getKey())
            ->get(['metric', 'value']);

        return Stats::fromRows($rows, $item);
    }

    /**
     * Totals for many items of one or more types in one query, keyed by
     * "{morph type}|{key}".
     *
     * @param  iterable<Model>  $items
     * @return array<string, Stats>
     */
    public function many(iterable $items): array
    {
        $items = collect($items)->filter();
        $stats = [];

        foreach ($items->groupBy(fn (Model $m) => $m->getMorphClass()) as $type => $group) {
            $rows = $this->query('counter')
                ->where('engageable_type', $type)
                ->whereIn('engageable_id', $group->map->getKey()->all())
                ->get(['engageable_id', 'metric', 'value'])
                ->groupBy('engageable_id');

            foreach ($group as $item) {
                $stats[$type.'|'.$item->getKey()] = Stats::fromRows($rows->get($item->getKey(), collect()), $item);
            }
        }

        return $stats;
    }

    /**
     * All-time totals of metrics, site-wide, for one type, or for some items
     * of it ($ids: an array or a subquery selecting ids).
     *
     * @param  array<int, string>  $metrics
     * @return array<string, int> metric => total
     */
    public function totals(array $metrics, ?string $type = null, mixed $ids = null): array
    {
        $rows = $this->query('counter')
            ->whereIn('metric', $metrics)
            ->when($type, fn (Builder $q) => $q->where('engageable_type', $type))
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('engageable_id', $ids))
            ->groupBy('metric')
            ->selectRaw('metric, coalesce(sum(value), 0) as total')
            ->pluck('total', 'metric');

        return array_map(fn (string $metric) => (int) ($rows[$metric] ?? 0), array_combine($metrics, $metrics));
    }

    /**
     * Site-wide (or one type's) totals of daily metrics over a date range.
     *
     * @param  array<int, string>  $metrics
     * @return array<string, int> metric => total
     */
    public function totalsBetween(CarbonInterface $from, CarbonInterface $to, array $metrics, ?string $type = null): array
    {
        $rows = $this->query('daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->whereIn('metric', $metrics)
            ->when($type, fn (Builder $q) => $q->where('engageable_type', $type))
            ->groupBy('metric')
            ->selectRaw('metric, coalesce(sum(value), 0) as total')
            ->pluck('total', 'metric');

        return array_map(fn (string $metric) => (int) ($rows[$metric] ?? 0), array_combine($metrics, $metrics));
    }

    /**
     * A metric's sub-totals over a date range, busiest first:
     * breakdownBetween($from, $to, 'shares') gives ['whatsapp' => 12, 'link' => 4].
     *
     * @return array<string, int> sub-key => total
     */
    public function breakdownBetween(CarbonInterface $from, CarbonInterface $to, string $metric, ?string $type = null): array
    {
        $prefix = $metric.'.';

        return $this->query('daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("metric LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'])
            ->when($type, fn (Builder $q) => $q->where('engageable_type', $type))
            ->groupBy('metric')
            ->selectRaw('metric, coalesce(sum(value), 0) as total')
            ->orderByDesc('total')
            ->pluck('total', 'metric')
            ->mapWithKeys(fn ($total, string $key) => [substr($key, strlen($prefix)) => (int) $total])
            ->all();
    }

    /**
     * Totals per day over a date range, days without activity as zeros.
     *
     * @param  array<int, string>  $metrics
     * @return array<string, array<string, int>> 'Y-m-d' => metric => total
     */
    public function dailyBetween(CarbonInterface $from, CarbonInterface $to, array $metrics, ?Model $item = null, ?string $type = null): array
    {
        $rows = $this->query('daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->whereIn('metric', $metrics)
            ->when($item, fn (Builder $q) => $q->where('engageable_type', $item->getMorphClass())->where('engageable_id', $item->getKey()))
            ->when($type && ! $item, fn (Builder $q) => $q->where('engageable_type', $type))
            ->groupBy('day', 'metric')
            ->selectRaw('day, metric, coalesce(sum(value), 0) as total')
            ->get()
            ->groupBy(fn (object $row) => substr((string) $row->day, 0, 10));

        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $values = $rows->get($day->toDateString(), collect())->pluck('total', 'metric');
            $days[$day->toDateString()] = array_map(fn (string $m) => (int) ($values[$m] ?? 0), array_combine($metrics, $metrics));
        }

        return $days;
    }

    /**
     * Items of one type ranked by a metric's daily total since a date.
     *
     * @return Collection<int, object{engageable_id: int|string, total: int}>
     */
    public function top(string $type, string $metric, CarbonInterface $since, int $limit = 10): Collection
    {
        return $this->query('daily')
            ->where('engageable_type', $type)
            ->where('metric', $metric)
            ->where('day', '>=', $since->toDateString())
            ->groupBy('engageable_id')
            ->selectRaw('engageable_id, sum(value) as total')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    public function table(string $key): string
    {
        return (string) config("matrix.tables.{$key}");
    }

    public function query(string $key): Builder
    {
        return $this->connection()->table($this->table($key));
    }

    public function connection(): ConnectionInterface
    {
        return $this->db->connection(config('matrix.connection'));
    }

    /**
     * Insert the row, or add to the existing one, in one statement.
     *
     * @param  array<string, mixed>  $key
     */
    private function add(string $table, array $key, int $amount, CarbonInterface $now): void
    {
        $value = $this->connection()->getQueryGrammar()->wrap('value');

        $this->connection()->table($table)->upsert(
            [$key + ['value' => $amount, 'created_at' => $now, 'updated_at' => $now]],
            array_keys($key),
            ['value' => $this->connection()->raw("{$value} + {$amount}"), 'updated_at' => $now],
        );
    }

    private function metric(string $metric): string
    {
        if ($metric === '' || strlen($metric) > 64 || ! preg_match('/^[A-Za-z0-9_.:\-]+$/', $metric)) {
            throw new InvalidArgumentException("Invalid matrix metric [{$metric}].");
        }

        return $metric;
    }
}

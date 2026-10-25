<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One metric for one item on one day (trending, charts, reports).
 */
class DailyCounter extends Model
{
    use HasScopes;

    /**
     * Period scopes on the rollup day: dayToday(), dayIn('last 7 calendar days')…
     */
    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->date('day', 'day');
    }

    protected $guarded = ['id'];

    protected $casts = [
        'value' => 'integer',
        'day' => 'date',
    ];

    public function getTable(): string
    {
        return $this->table ?? (string) config('matrix.tables.daily');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('matrix.connection');
    }

    public function engageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeMetric(Builder $query, string $metric): Builder
    {
        return $query->where($query->qualifyColumn('metric'), $metric);
    }

    /**
     * One row per item and day with the given metrics as columns. Metric
     * "reactions.like" becomes column "reactions_like".
     *
     * @param  array<int, string>  $metrics
     */
    public function scopePivoted(Builder $query, array $metrics): Builder
    {
        $grammar = $this->getConnection()->getQueryGrammar();

        $query->select('engageable_type', 'engageable_id', 'day')
            ->selectRaw('min('.$grammar->wrap('id').') as '.$grammar->wrap('id'))
            ->groupBy('engageable_type', 'engageable_id', 'day');

        foreach ($metrics as $metric) {
            $query->selectRaw(
                'coalesce(sum(case when '.$grammar->wrap('metric').' = ? then '.$grammar->wrap('value').' end), 0) as '.$grammar->wrap(Counter::column($metric)),
                [$metric],
            );
        }

        return $query;
    }

    public function scopeSince(Builder $query, \DateTimeInterface|string $day): Builder
    {
        $day = $day instanceof \DateTimeInterface ? $day->format('Y-m-d') : $day;

        return $query->where($query->qualifyColumn('day'), '>=', $day);
    }
}

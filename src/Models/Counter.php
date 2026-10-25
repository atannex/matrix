<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Scopable\Concerns\HasScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * One running total ("views", "reactions.like", "ratings_sum"…) for one item.
 * Written by the engine; corrected by "matrix:recount".
 */
class Counter extends Model
{
    use HasScopes;

    protected $guarded = ['id'];

    protected $casts = ['value' => 'integer'];

    public function getTable(): string
    {
        return $this->table ?? (string) config('matrix.tables.counter');
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
     * One row per item with the given metrics as columns (admin tables,
     * exports). Metric "reactions.like" becomes column "reactions_like".
     *
     * @param  array<int, string>  $metrics
     */
    public function scopePivoted(Builder $query, array $metrics): Builder
    {
        $grammar = DB::connection($this->getConnectionName())->getQueryGrammar();

        $query->select('engageable_type', 'engageable_id')
            ->selectRaw('min('.$grammar->wrap('id').') as '.$grammar->wrap('id'))
            ->selectRaw('max('.$grammar->wrap('updated_at').') as '.$grammar->wrap('updated_at'))
            ->groupBy('engageable_type', 'engageable_id');

        foreach ($metrics as $metric) {
            $query->selectRaw(
                'coalesce(sum(case when '.$grammar->wrap('metric').' = ? then '.$grammar->wrap('value').' end), 0) as '.$grammar->wrap(self::column($metric)),
                [$metric],
            );
        }

        return $query;
    }

    public static function column(string $metric): string
    {
        return str_replace(['.', ':', '-', ' '], '_', $metric);
    }
}

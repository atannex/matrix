<?php

declare(strict_types=1);

namespace Atannex\Matrix\Concerns;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Matrix;
use Atannex\Matrix\Models\Counter;
use Atannex\Matrix\Stats;
use Atannex\Matrix\Subject;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Makes a model engageable. Implement Atannex\Matrix\Contracts\Engageable
 * alongside it.
 *
 *     $post->matrix()->like();
 *     $post->matrix()->stats()->views;
 *
 *     Post::withMatrix('views', 'reactions.like')->get();     // ->matrix_views
 *     Post::orderByMatrix('views')->limit(10)->get();
 *     Post::orderByMatrixSince('views', now()->subWeek())->get();
 *     Post::trending(now()->subDays(7))->get();
 *     Post::whereEngagedBy($actor, 'bookmark')->get();         // their saved posts
 *
 * @mixin Model
 */
trait InteractsWithMatrix
{
    public static function bootInteractsWithMatrix(): void
    {
        static::deleted(function (Model $model) {
            $soft = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();

            if (! $soft && config('matrix.cleanup_on_delete', true)) {
                app(Matrix::class)->forget($model);
            }
        });
    }

    /**
     * Engage with this item as the given actor (the current one by default).
     */
    public function matrix(?Actor $actor = null): Subject
    {
        return app(Matrix::class)->for($this, $actor);
    }

    public function acceptsEngagement(?string $signal = null): bool
    {
        return true;
    }

    public function matrixSignals(): ?array
    {
        return null;
    }

    public function matrixStats(): Stats
    {
        return app(Matrix::class)->stats($this);
    }

    public function matrixToken(): string
    {
        return app(Matrix::class)->token($this);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function matrixCounters(): MorphMany
    {
        return $this->morphMany(config('matrix.models.counter'), 'engageable');
    }

    public function matrixDaily(): MorphMany
    {
        return $this->morphMany(config('matrix.models.daily'), 'engageable');
    }

    public function views(): MorphMany
    {
        return $this->morphMany(config('matrix.models.view'), 'engageable');
    }

    public function clicks(): MorphMany
    {
        return $this->morphMany(config('matrix.models.click'), 'engageable');
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(config('matrix.models.share'), 'engageable');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(config('matrix.models.reaction'), 'engageable');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(config('matrix.models.rating'), 'engageable');
    }

    public function reviews(): MorphMany
    {
        return $this->morphMany(config('matrix.models.review'), 'engageable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(config('matrix.models.comment'), 'engageable');
    }

    public function bookmarks(): MorphMany
    {
        return $this->morphMany(config('matrix.models.bookmark'), 'engageable');
    }

    public function follows(): MorphMany
    {
        return $this->morphMany(config('matrix.models.follow'), 'engageable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(config('matrix.models.report'), 'engageable');
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Eager-load every running total (read with ->matrixStats()).
     */
    public function scopeWithMatrixStats(Builder $query): Builder
    {
        return $query->with('matrixCounters');
    }

    /**
     * Add metrics as columns: "views" → matrix_views, "reactions.like" →
     * matrix_reactions_like.
     */
    public function scopeWithMatrix(Builder $query, string ...$metrics): Builder
    {
        $this->selectAllColumns($query);

        foreach ($metrics as $metric) {
            $query->addSelect([static::matrixAlias($metric) => static::matrixMetric($metric)]);
        }

        return $query;
    }

    /**
     * Add a metric's total since a day as a column ("matrix_{metric}_since").
     */
    public function scopeWithMatrixSince(Builder $query, string $metric, CarbonInterface $since, ?string $alias = null): Builder
    {
        $this->selectAllColumns($query);

        return $query->addSelect([($alias ?? static::matrixAlias($metric).'_since') => static::matrixMetricSince($metric, $since)]);
    }

    public function scopeOrderByMatrix(Builder $query, string $metric, string $direction = 'desc'): Builder
    {
        return $query->orderBy(static::matrixMetric($metric), $this->direction($direction));
    }

    public function scopeOrderByMatrixSince(Builder $query, string $metric, CarbonInterface $since, string $direction = 'desc'): Builder
    {
        return $query->orderBy(static::matrixMetricSince($metric, $since), $this->direction($direction));
    }

    /**
     * Only items whose total passes the comparison (missing totals are 0).
     */
    public function scopeWhereMatrix(Builder $query, string $metric, string $operator, int|float $value): Builder
    {
        if (! in_array($operator, ['=', '>', '>=', '<', '<=', '<>', '!='], true)) {
            throw new InvalidArgumentException("Invalid operator [{$operator}].");
        }

        return $query->where(static::matrixMetric($metric), $operator, $value);
    }

    /**
     * Only items with some of this metric since a day.
     */
    public function scopeWhereMatrixSince(Builder $query, string $metric, CarbonInterface $since): Builder
    {
        return $query->whereExists(static::dailyQuery($metric, $since)->where('value', '>', 0)->selectRaw('1'));
    }

    /**
     * Ordered by a weighted engagement score over the daily rollups since
     * a day (default: the last 7 days, weights from "score.weights").
     *
     * @param  array<string, int|float>|null  $weights
     */
    public function scopeTrending(Builder $query, ?CarbonInterface $since = null, ?array $weights = null): Builder
    {
        $this->selectAllColumns($query);

        return $query
            ->addSelect(['matrix_trending' => static::matrixScoreSince($since ?? now()->subDays(7)->startOfDay(), $weights)])
            ->orderByDesc('matrix_trending');
    }

    /**
     * Items the actor engaged with through a signal ("bookmark", "follow",
     * "reaction", …), e.g. their saved stories.
     *
     * @param  array<string, mixed>  $where  extra column filters (e.g. ['collection' => 'later'])
     */
    public function scopeWhereEngagedBy(Builder $query, Actor $actor, string $signal, array $where = []): Builder
    {
        $model = new (config("matrix.models.{$signal}"));
        $table = $model->getTable();

        return $query->whereExists(fn (QueryBuilder $q) => $q
            ->selectRaw('1')
            ->from($table)
            ->whereColumn("{$table}.engageable_id", $this->qualifyColumn($this->getKeyName()))
            ->where("{$table}.engageable_type", $this->getMorphClass())
            ->where("{$table}.actor_key", $actor->key)
            ->where(fn (QueryBuilder $w) => collect($where)->each(fn ($value, $column) => $w->where("{$table}.{$column}", $value))));
    }

    /**
     * The actor's reaction type as a "matrix_reaction" column.
     */
    public function scopeWithReactionOf(Builder $query, Actor $actor): Builder
    {
        $model = new (config('matrix.models.reaction'));

        $this->selectAllColumns($query);

        return $query->addSelect(['matrix_reaction' => $model->newQuery()
            ->select('type')
            ->whereColumn('engageable_id', $this->qualifyColumn($this->getKeyName()))
            ->where('engageable_type', $this->getMorphClass())
            ->where('actor_key', $actor->key)
            ->limit(1)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Subqueries (for your own selects and orders)
    |--------------------------------------------------------------------------
    */

    /**
     * Correlated subquery: this row's running total of a metric (0 if none).
     */
    public static function matrixMetric(string $metric): Builder
    {
        $model = new static;
        $counter = new (config('matrix.models.counter'));
        $value = $counter->getConnection()->getQueryGrammar()->wrap('value');

        return $counter->newQuery()
            ->selectRaw("coalesce(max({$value}), 0)")
            ->whereColumn('engageable_id', $model->qualifyColumn($model->getKeyName()))
            ->where('engageable_type', $model->getMorphClass())
            ->where('metric', $metric);
    }

    /**
     * Correlated subquery: weighted sum of this row's running totals (the
     * all-time counterpart of matrixScoreSince()).
     *
     * @param  array<string, int|float>|null  $weights
     */
    public static function matrixScore(?array $weights = null): Builder
    {
        $weights = array_filter($weights ?? (array) config('matrix.score.weights', []));

        if ($weights === []) {
            throw new InvalidArgumentException('A score needs at least one weighted metric.');
        }

        $model = new static;
        $counter = new (config('matrix.models.counter'));
        $grammar = $counter->getConnection()->getQueryGrammar();
        $cases = implode(' ', array_fill(0, count($weights), 'when ? then ?'));
        $bindings = [];

        foreach ($weights as $metric => $weight) {
            array_push($bindings, $metric, $weight);
        }

        return $counter->newQuery()
            ->whereColumn('engageable_id', $model->qualifyColumn($model->getKeyName()))
            ->where('engageable_type', $model->getMorphClass())
            ->whereIn('metric', array_keys($weights))
            ->selectRaw('coalesce(sum('.$grammar->wrap('value').' * case '.$grammar->wrap('metric')." {$cases} else 0 end), 0)", $bindings);
    }

    /**
     * Correlated subquery: this row's average rating (0 when unrated), from
     * the "ratings" and "ratings_sum" totals.
     */
    public static function matrixAverageRating(?string $dimension = null): Builder
    {
        $model = new static;
        $counter = new (config('matrix.models.counter'));
        $grammar = $counter->getConnection()->getQueryGrammar();
        [$value, $metric] = [$grammar->wrap('value'), $grammar->wrap('metric')];
        [$count, $sum] = $dimension === null || $dimension === 'overall'
            ? ['ratings', 'ratings_sum']
            : ["ratings:{$dimension}", "ratings_sum:{$dimension}"];

        return $counter->newQuery()
            ->selectRaw(
                "coalesce(max(case when {$metric} = ? then {$value} end) * 1.0 / nullif(max(case when {$metric} = ? then {$value} end), 0), 0)",
                [$sum, $count],
            )
            ->whereColumn('engageable_id', $model->qualifyColumn($model->getKeyName()))
            ->where('engageable_type', $model->getMorphClass())
            ->whereIn('metric', [$count, $sum]);
    }

    /**
     * Correlated subquery: this row's daily totals of a metric since a day.
     */
    public static function matrixMetricSince(string $metric, CarbonInterface $since): Builder
    {
        $value = (new (config('matrix.models.daily')))->getConnection()->getQueryGrammar()->wrap('value');

        return static::dailyQuery($metric, $since)->selectRaw("coalesce(sum({$value}), 0)");
    }

    /**
     * Correlated subquery: weighted sum of daily metrics since a day.
     *
     * @param  array<string, int|float>|null  $weights
     */
    public static function matrixScoreSince(CarbonInterface $since, ?array $weights = null): Builder
    {
        $weights = array_filter($weights ?? (array) config('matrix.score.weights', []));

        if ($weights === []) {
            throw new InvalidArgumentException('Trending needs at least one weighted metric.');
        }

        $grammar = (new (config('matrix.models.daily')))->getConnection()->getQueryGrammar();
        $cases = implode(' ', array_fill(0, count($weights), 'when ? then ?'));
        $bindings = [];

        foreach ($weights as $metric => $weight) {
            array_push($bindings, $metric, $weight);
        }

        return static::dailyQuery(null, $since)
            ->whereIn('metric', array_keys($weights))
            ->selectRaw('coalesce(sum('.$grammar->wrap('value').' * case '.$grammar->wrap('metric')." {$cases} else 0 end), 0)", $bindings);
    }

    public static function matrixAlias(string $metric): string
    {
        return 'matrix_'.Counter::column($metric);
    }

    private static function dailyQuery(?string $metric, CarbonInterface $since): Builder
    {
        $model = new static;
        $daily = new (config('matrix.models.daily'));

        return $daily->newQuery()
            ->whereColumn('engageable_id', $model->qualifyColumn($model->getKeyName()))
            ->where('engageable_type', $model->getMorphClass())
            ->when($metric, fn (Builder $q) => $q->where('metric', $metric))
            ->where('day', '>=', $since->toDateString());
    }

    private function selectAllColumns(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }
    }

    private function direction(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }
}

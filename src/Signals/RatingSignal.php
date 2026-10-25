<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Rated;
use Atannex\Matrix\Events\Unrated;
use Atannex\Matrix\Models\Rating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Scores between "ratings.min" and "ratings.max", one per actor per item and
 * dimension ("overall", or criteria such as "accuracy", "clarity").
 *
 * Metrics (overall):     ratings, ratings_sum, ratings.{score}
 * Metrics (dimension d): ratings:d, ratings_sum:d, ratings:d.{score}
 */
class RatingSignal extends Signal
{
    public function name(): string
    {
        return 'rating';
    }

    public function identity(): ?array
    {
        return ['dimension'];
    }

    public function owns(string $metric): bool
    {
        return str_starts_with($metric, 'ratings');
    }

    public function recount(string $type, int|string $id): array
    {
        $rows = $this->base()
            ->where('engageable_type', $type)
            ->where('engageable_id', $id)
            ->groupBy('dimension', 'value')
            ->selectRaw('dimension, value, count(*) as total')
            ->get();

        $metrics = [];

        foreach ($rows as $row) {
            $suffix = $row->dimension === Rating::OVERALL ? '' : ':'.$row->dimension;
            $metrics["ratings{$suffix}"] = ($metrics["ratings{$suffix}"] ?? 0) + (int) $row->total;
            $metrics["ratings_sum{$suffix}"] = ($metrics["ratings_sum{$suffix}"] ?? 0) + (int) $row->total * (int) $row->value;
            $metrics["ratings{$suffix}.{$row->value}"] = (int) $row->total;
        }

        return $metrics;
    }

    public function current(Model $item, Actor $actor, string $dimension = Rating::OVERALL): ?int
    {
        $value = $this->about($item)->where('actor_key', $actor->key)->where('dimension', $dimension)->value('value');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<string, int> dimension => score
     */
    public function all(Model $item, Actor $actor): array
    {
        return $this->about($item)->where('actor_key', $actor->key)->pluck('value', 'dimension')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Give or change a score. With "ratings.toggle", giving the score you
     * already gave takes it back. Returns the actor's score afterwards.
     */
    public function rate(Model $item, Actor $actor, int $value, string $dimension = Rating::OVERALL, ?string $comment = null): ?int
    {
        $this->validate($value, $dimension);
        $this->matrix()->guard($item, $this->name(), $actor);

        $previous = $this->current($item, $actor, $dimension);

        if ($previous === $value && config('matrix.ratings.toggle', true)) {
            $this->unrate($item, $actor, $dimension);

            return null;
        }

        $rating = $this->newQuery()->updateOrCreate(
            $this->subjectKey($item) + ['actor_key' => $actor->key, 'dimension' => $dimension],
            [...$actor->attributes(), 'value' => $value, 'comment' => $comment === null ? null : mb_substr(trim($comment), 0, 2000)],
        );

        $this->refresh($item);

        if ($previous === null) {
            $suffix = $dimension === Rating::OVERALL ? '' : ':'.$dimension;
            $this->counters()->increment($item, [], ["ratings{$suffix}" => 1]);
        }

        $this->matrix()->dispatch(new Rated($item, $actor, $rating, ['value' => $value, 'previous' => $previous, 'dimension' => $dimension]));

        return $value;
    }

    public function unrate(Model $item, Actor $actor, string $dimension = Rating::OVERALL): ?int
    {
        $this->matrix()->guard($item, $this->name(), $actor);

        $rating = $this->about($item)->where('actor_key', $actor->key)->where('dimension', $dimension)->first();

        if ($rating === null) {
            return null;
        }

        $rating->delete();

        $this->refresh($item);
        $this->matrix()->dispatch(new Unrated($item, $actor, $rating, ['value' => $rating->value, 'dimension' => $dimension]));

        return (int) $rating->value;
    }

    /**
     * @throws ValidationException
     */
    public function validate(int $value, string $dimension = Rating::OVERALL, string $field = 'rating'): void
    {
        $min = (int) config('matrix.ratings.min', 1);
        $max = (int) config('matrix.ratings.max', 5);

        if ($value < $min || $value > $max) {
            throw ValidationException::withMessages([$field => __('Choose a score from :min to :max.', ['min' => $min, 'max' => $max])]);
        }

        $dimensions = config('matrix.ratings.dimensions');
        $known = is_array($dimensions) ? in_array($dimension, [Rating::OVERALL, ...$dimensions], true) : preg_match('/^[a-z0-9_]{1,40}$/', $dimension);

        if (! $known) {
            throw ValidationException::withMessages(['dimension' => __('Unknown rating criterion.')]);
        }
    }
}

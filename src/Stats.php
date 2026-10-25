<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use ArrayAccess;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use LogicException;

/**
 * An item's running totals, plus the scores derived from them.
 *
 *     $stats->views                     // any metric as a property…
 *     $stats->get('reactions.love')     // …or by name
 *     $stats->reaction('like')
 *     $stats->averageRating()           // 4.2
 *     $stats->bayesianRating()          // fair ranking with few votes
 *     $stats->approval()                // Wilson lower bound of positive reactions
 *     $stats->score()                   // weighted engagement score
 *     $stats->hotness()                 // score decayed by age
 *
 * @property-read int $views
 * @property-read int $unique_viewers
 * @property-read int $reads
 * @property-read int $read_seconds
 * @property-read int $clicks
 * @property-read int $shares
 * @property-read int $comments
 * @property-read int $reactions
 * @property-read int $ratings
 * @property-read int $ratings_sum
 * @property-read int $reviews
 * @property-read int $reviews_rating_sum
 * @property-read int $reviews_rated
 * @property-read int $bookmarks
 * @property-read int $followers
 * @property-read int $reports
 *
 * @implements ArrayAccess<string, int>
 * @implements Arrayable<string, int>
 */
final class Stats implements Arrayable, ArrayAccess, JsonSerializable
{
    /**
     * @param  array<string, int>  $values
     */
    public function __construct(
        private readonly array $values = [],
        private readonly ?Model $item = null,
    ) {}

    /**
     * @param  iterable<object|array{metric: string, value: int}>  $rows
     */
    public static function fromRows(iterable $rows, ?Model $item = null): self
    {
        $values = [];

        foreach ($rows as $row) {
            $row = (array) ($row instanceof Model ? $row->getAttributes() : $row);
            $values[$row['metric']] = (int) $row['value'];
        }

        return new self($values, $item);
    }

    public function get(string $metric, int $default = 0): int
    {
        return $this->values[$metric] ?? $default;
    }

    public function has(string $metric): bool
    {
        return ($this->values[$metric] ?? 0) !== 0;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->values;
    }

    /*
    |--------------------------------------------------------------------------
    | Breakdowns
    |--------------------------------------------------------------------------
    */

    public function reaction(string $type): int
    {
        return $this->get("reactions.{$type}");
    }

    public function likes(): int
    {
        return $this->reaction('like');
    }

    public function dislikes(): int
    {
        return $this->reaction('dislike');
    }

    /**
     * @return array<string, int> reaction type => count, most first
     */
    public function reactionBreakdown(): array
    {
        return $this->family('reactions');
    }

    /**
     * @return array<string, int> platform => shares, most first
     */
    public function shareBreakdown(): array
    {
        return $this->family('shares');
    }

    /**
     * @return array<string, int>
     */
    private function family(string $prefix): array
    {
        $family = [];

        foreach ($this->values as $metric => $value) {
            if (str_starts_with($metric, $prefix.'.') && $value > 0) {
                $family[substr($metric, strlen($prefix) + 1)] = $value;
            }
        }

        arsort($family);

        return $family;
    }

    /*
    |--------------------------------------------------------------------------
    | Ratings
    |--------------------------------------------------------------------------
    */

    public function ratingsCount(?string $dimension = null): int
    {
        return $this->get($this->dimensional('ratings', $dimension));
    }

    public function averageRating(?string $dimension = null, int $precision = 2): float
    {
        $count = $this->ratingsCount($dimension);

        return $count > 0 ? round($this->get($this->dimensional('ratings_sum', $dimension)) / $count, $precision) : 0.0;
    }

    /**
     * The average pulled toward a prior mean, so one 5-star vote does not
     * outrank fifty 4.8s: (C·m + Σ) / (C + n).
     */
    public function bayesianRating(?string $dimension = null, ?float $mean = null, ?int $weight = null, int $precision = 2): float
    {
        $mean ??= (float) config('matrix.ratings.bayesian.mean', 3.0);
        $weight ??= (int) config('matrix.ratings.bayesian.weight', 5);

        $count = $this->ratingsCount($dimension);
        $sum = $this->get($this->dimensional('ratings_sum', $dimension));

        return $weight + $count > 0 ? round(($weight * $mean + $sum) / ($weight + $count), $precision) : 0.0;
    }

    /**
     * @return array<int, int> score => share of ratings in percent (needs the
     *                         "ratings.value.{n}" histogram metrics)
     */
    public function ratingHistogram(?string $dimension = null): array
    {
        $min = (int) config('matrix.ratings.min', 1);
        $max = (int) config('matrix.ratings.max', 5);
        $total = max(1, $this->ratingsCount($dimension));
        $histogram = [];

        for ($score = $max; $score >= $min; $score--) {
            $histogram[$score] = (int) round($this->get($this->dimensional('ratings', $dimension).".{$score}") * 100 / $total);
        }

        return $histogram;
    }

    public function averageReviewRating(int $precision = 2): float
    {
        $rated = $this->get('reviews_rated');

        return $rated > 0 ? round($this->get('reviews_rating_sum') / $rated, $precision) : 0.0;
    }

    private function dimensional(string $metric, ?string $dimension): string
    {
        return $dimension === null || $dimension === 'overall' ? $metric : "{$metric}:{$dimension}";
    }

    /*
    |--------------------------------------------------------------------------
    | Scores
    |--------------------------------------------------------------------------
    */

    public function averageReadSeconds(): int
    {
        $reads = $this->get('reads');

        return $reads > 0 ? intdiv($this->get('read_seconds'), $reads) : 0;
    }

    /**
     * Lower bound of the Wilson score interval for positive vs negative
     * reactions (config "reactions.*.sentiment"): how sure we are that
     * people like it, rewarding volume. 0..1.
     */
    public function approval(float $z = 1.96): float
    {
        [$positive, $negative] = [0, 0];

        foreach ($this->reactionBreakdown() as $type => $count) {
            $sentiment = (int) config("matrix.reactions.{$type}.sentiment", 0);
            $sentiment > 0 ? $positive += $count : ($sentiment < 0 ? $negative += $count : null);
        }

        $n = $positive + $negative;

        if ($n === 0) {
            return 0.0;
        }

        $p = $positive / $n;
        $z2 = $z * $z;

        return round(($p + $z2 / (2 * $n) - $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n)) / (1 + $z2 / $n), 4);
    }

    /**
     * Weighted sum of the metrics (config "score.weights" by default).
     *
     * @param  array<string, int|float>|null  $weights
     */
    public function score(?array $weights = null): float
    {
        $score = 0.0;

        foreach ($weights ?? (array) config('matrix.score.weights', []) as $metric => $weight) {
            $score += $this->get($metric) * $weight;
        }

        return $score;
    }

    /**
     * Score decayed by age: score / (hours + 2) ^ gravity. The age comes
     * from $since, else the item's published_at or created_at.
     */
    public function hotness(?CarbonInterface $since = null, ?float $gravity = null): float
    {
        $since ??= $this->item?->getAttribute('published_at') ?? $this->item?->getAttribute('created_at');

        if ($since === null) {
            throw new LogicException('Hotness needs the item\'s age: pass $since.');
        }

        $hours = max(0, $since->diffInMinutes(now()) / 60);

        return round($this->score() / (($hours + 2) ** ($gravity ?? (float) config('matrix.score.gravity', 1.8))), 6);
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function __get(string $metric): int
    {
        return $this->get($metric);
    }

    public function __isset(string $metric): bool
    {
        return true;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): int
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Stats are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Stats are read-only.');
    }

    /**
     * @return array<string, int|float|array<string, int>>
     */
    public function toArray(): array
    {
        return [
            ...$this->values,
            'average_rating' => $this->averageRating(),
            'bayesian_rating' => $this->bayesianRating(),
            'approval' => $this->approval(),
            'average_read_seconds' => $this->averageReadSeconds(),
            'score' => $this->score(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

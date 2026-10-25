<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Enums\ViewOutcome;
use Atannex\Matrix\Events\Viewed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Page views: one row per actor holding how often they viewed the item. A
 * reload inside the window ("signals.view.window" minutes) is not a view.
 *
 * Metrics: views, unique_viewers (daily: unique viewers that day).
 */
class ViewSignal extends Signal
{
    public function name(): string
    {
        return 'view';
    }

    public function interactive(): bool
    {
        return false;
    }

    public function summed(): ?string
    {
        return 'view_count';
    }

    public function owns(string $metric): bool
    {
        return in_array($metric, ['views', 'unique_viewers'], true);
    }

    public function recount(string $type, int|string $id): array
    {
        $row = $this->base()
            ->where('engageable_type', $type)
            ->where('engageable_id', $id)
            ->selectRaw('coalesce(sum(view_count), 0) as views, count(*) as unique_viewers')
            ->first();

        return array_filter(['views' => (int) $row->views, 'unique_viewers' => (int) $row->unique_viewers]);
    }

    public function record(Model $item, Actor $actor): ViewOutcome
    {
        if ($actor->isBot() || ! $this->matrix()->guard($item, $this->name(), $actor)) {
            return ViewOutcome::Ignored;
        }

        $outcome = $this->register($item, $actor);

        [$totals, $daily] = match ($outcome) {
            ViewOutcome::FirstView => [['views' => 1, 'unique_viewers' => 1], ['views' => 1, 'unique_viewers' => 1]],
            ViewOutcome::FirstToday => [['views' => 1], ['views' => 1, 'unique_viewers' => 1]],
            ViewOutcome::Repeat => [['views' => 1], ['views' => 1]],
            ViewOutcome::Ignored => [[], []],
        };

        if ($outcome->counts()) {
            $this->counters()->increment($item, $totals, $daily);
            $this->matrix()->dispatch(new Viewed($item, $actor, context: ['outcome' => $outcome]));
        }

        return $outcome;
    }

    private function register(Model $item, Actor $actor): ViewOutcome
    {
        $now = now();

        $view = $this->newQuery()->createOrFirst(
            $this->subjectKey($item) + ['actor_key' => $actor->key],
            [...$actor->attributes(['session_id', 'user_agent']), 'view_count' => 1, 'last_viewed_at' => $now],
        );

        if ($view->wasRecentlyCreated) {
            return ViewOutcome::FirstView;
        }

        $lastViewed = $view->last_viewed_at ?? $view->created_at;
        $windowStart = $now->copy()->subMinutes((int) $this->config('window', 30));

        // Conditional update: two beacons racing for the same view count once.
        $counted = $this->newQuery()
            ->whereKey($view->getKey())
            ->where(fn (Builder $q) => $q->whereNull('last_viewed_at')->orWhere('last_viewed_at', '<=', $windowStart))
            ->toBase()
            ->update([
                'view_count' => $view->view_count + 1,
                'last_viewed_at' => $now,
                'user_id' => $actor->userId() ?? $view->user_id,
                'updated_at' => $now,
            ]);

        if ($counted === 0) {
            return ViewOutcome::Ignored;
        }

        return $lastViewed->lt($now->copy()->startOfDay()) ? ViewOutcome::FirstToday : ViewOutcome::Repeat;
    }
}

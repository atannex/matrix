<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Shared;
use Atannex\Matrix\Models\Share;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Shares: one row per actor and platform, counting repeats.
 *
 * Metrics: shares, shares.{platform}.
 */
class ShareSignal extends Signal
{
    public function name(): string
    {
        return 'share';
    }

    public function identity(): ?array
    {
        return ['platform'];
    }

    public function summed(): ?string
    {
        return 'share_count';
    }

    public function owns(string $metric): bool
    {
        return $metric === 'shares' || str_starts_with($metric, 'shares.');
    }

    public function recount(string $type, int|string $id): array
    {
        $platforms = $this->grouped($type, $id, 'platform', 'sum(share_count)')->filter();
        $metrics = $platforms->mapWithKeys(fn (int $count, string $platform) => ["shares.{$platform}" => $count])->all();

        return $platforms->isEmpty() ? [] : ['shares' => $platforms->sum()] + $metrics;
    }

    public function record(Model $item, Actor $actor, string $platform): ?Share
    {
        $platform = $this->platform($platform);

        if (! $this->matrix()->guard($item, $this->name(), $actor)) {
            return null;
        }

        $share = $this->newQuery()->firstOrNew($this->subjectKey($item) + ['actor_key' => $actor->key, 'platform' => $platform]);

        if ($share->exists) {
            $share->increment('share_count');
        } else {
            $share->fill([...$actor->attributes(['session_id', 'user_agent']), 'share_count' => 1])->save();
        }

        $this->counters()->increment($item, ['shares' => 1, "shares.{$platform}" => 1]);
        $this->matrix()->dispatch(new Shared($item, $actor, $share, ['platform' => $platform]));

        return $share;
    }

    /**
     * @throws ValidationException
     */
    public function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        $allowed = $this->config('platforms');

        if (! preg_match('/^[a-z0-9_\-]{1,40}$/', $platform) || (is_array($allowed) && ! in_array($platform, $allowed, true))) {
            throw ValidationException::withMessages(['platform' => __('Unknown share platform.')]);
        }

        return $platform;
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Followed;
use Atannex\Matrix\Events\Unfollowed;
use Atannex\Matrix\Models\Follow;
use Illuminate\Database\Eloquent\Model;

/**
 * Following an item (an author, a topic, a story) for updates.
 *
 * Metrics: followers.
 */
class FollowSignal extends Signal
{
    public function name(): string
    {
        return 'follow';
    }

    public function owns(string $metric): bool
    {
        return $metric === 'followers';
    }

    public function recount(string $type, int|string $id): array
    {
        $count = $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->count();

        return $count > 0 ? ['followers' => $count] : [];
    }

    public function has(Model $item, Actor $actor): bool
    {
        return $this->about($item)->where('actor_key', $actor->key)->exists();
    }

    public function follow(Model $item, Actor $actor, bool $notify = true): Follow
    {
        $this->matrix()->guard($item, $this->name(), $actor);

        $follow = $this->newQuery()->updateOrCreate(
            $this->subjectKey($item) + ['actor_key' => $actor->key],
            ['user_id' => $actor->userId(), 'ip_address' => $actor->anonymizedIp(), 'notify' => $notify],
        );

        if ($follow->wasRecentlyCreated) {
            $this->refresh($item);
            $this->counters()->increment($item, [], ['followers' => 1]);
            $this->matrix()->dispatch(new Followed($item, $actor, $follow));
        }

        return $follow;
    }

    public function unfollow(Model $item, Actor $actor): bool
    {
        $this->matrix()->guard($item, $this->name(), $actor);

        $follow = $this->about($item)->where('actor_key', $actor->key)->first();

        if ($follow === null) {
            return false;
        }

        $follow->delete();

        $this->refresh($item);
        $this->matrix()->dispatch(new Unfollowed($item, $actor, $follow));

        return true;
    }

    public function toggle(Model $item, Actor $actor): bool
    {
        if ($this->has($item, $actor)) {
            $this->unfollow($item, $actor);

            return false;
        }

        $this->follow($item, $actor);

        return true;
    }
}

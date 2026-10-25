<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Bookmarked;
use Atannex\Matrix\Events\Unbookmarked;
use Atannex\Matrix\Models\Bookmark;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Saved items, in named collections per actor.
 *
 * Metrics: bookmarks (distinct actors who saved the item).
 */
class BookmarkSignal extends Signal
{
    public function name(): string
    {
        return 'bookmark';
    }

    public function identity(): ?array
    {
        return ['collection'];
    }

    public function owns(string $metric): bool
    {
        return $metric === 'bookmarks';
    }

    public function recount(string $type, int|string $id): array
    {
        $count = $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->distinct()->count('actor_key');

        return $count > 0 ? ['bookmarks' => $count] : [];
    }

    public function has(Model $item, Actor $actor, ?string $collection = null): bool
    {
        return $this->about($item)->where('actor_key', $actor->key)
            ->when($collection, fn ($q) => $q->where('collection', $collection))
            ->exists();
    }

    public function add(Model $item, Actor $actor, string $collection = Bookmark::DEFAULT_COLLECTION, ?string $note = null): Bookmark
    {
        $collection = $this->collection($collection);
        $this->matrix()->guard($item, $this->name(), $actor);

        $isNew = ! $this->has($item, $actor);

        $bookmark = $this->newQuery()->updateOrCreate(
            $this->subjectKey($item) + ['actor_key' => $actor->key, 'collection' => $collection],
            ['user_id' => $actor->userId(), 'ip_address' => $actor->anonymizedIp()] + ($note === null ? [] : ['note' => mb_substr($note, 0, 500)]),
        );

        if ($bookmark->wasRecentlyCreated) {
            $this->refresh($item);
            $this->counters()->increment($item, [], $isNew ? ['bookmarks' => 1] : []);
            $this->matrix()->dispatch(new Bookmarked($item, $actor, $bookmark, ['collection' => $collection]));
        }

        return $bookmark;
    }

    /**
     * Remove from one collection (or all); true when something was removed.
     */
    public function remove(Model $item, Actor $actor, ?string $collection = null): bool
    {
        $this->matrix()->guard($item, $this->name(), $actor);

        $removed = $this->about($item)->where('actor_key', $actor->key)
            ->when($collection, fn ($q) => $q->where('collection', $collection))
            ->get();

        if ($removed->isEmpty()) {
            return false;
        }

        $this->newQuery()->whereKey($removed->modelKeys())->delete();

        $this->refresh($item);
        $this->matrix()->dispatch(new Unbookmarked($item, $actor, $removed->first(), ['collection' => $collection]));

        return true;
    }

    /**
     * Returns whether the item is saved in the collection afterwards.
     */
    public function toggle(Model $item, Actor $actor, string $collection = Bookmark::DEFAULT_COLLECTION): bool
    {
        if ($this->has($item, $actor, $collection)) {
            $this->remove($item, $actor, $collection);

            return false;
        }

        $this->add($item, $actor, $collection);

        return true;
    }

    /**
     * The actor's collections with how many items each holds.
     *
     * @return Collection<string, int>
     */
    public function collections(Actor $actor): Collection
    {
        return $this->base()->where('actor_key', $actor->key)
            ->groupBy('collection')
            ->selectRaw('collection, count(*) as total')
            ->pluck('total', 'collection')
            ->map(fn ($total) => (int) $total);
    }

    private function collection(string $collection): string
    {
        $collection = trim($collection);

        if ($collection === '' || mb_strlen($collection) > 60) {
            throw ValidationException::withMessages(['collection' => __('Give the collection a name of up to 60 characters.')]);
        }

        return $collection;
    }
}

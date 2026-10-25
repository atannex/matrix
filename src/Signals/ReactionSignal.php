<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Reacted;
use Atannex\Matrix\Events\Unreacted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Reactions (config "reactions"): one per actor per item. Reacting with
 * another type switches it.
 *
 * Metrics: reactions, reactions.{type}.
 */
class ReactionSignal extends Signal
{
    public function name(): string
    {
        return 'reaction';
    }

    public function owns(string $metric): bool
    {
        return $metric === 'reactions' || str_starts_with($metric, 'reactions.');
    }

    public function recount(string $type, int|string $id): array
    {
        $types = $this->grouped($type, $id, 'type', 'count(*)')->filter();
        $metrics = $types->mapWithKeys(fn (int $count, string $reaction) => ["reactions.{$reaction}" => $count])->all();

        return $types->isEmpty() ? [] : ['reactions' => $types->sum()] + $metrics;
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys((array) config('matrix.reactions', []));
    }

    public function current(Model $item, Actor $actor): ?string
    {
        return $this->about($item)->where('actor_key', $actor->key)->value('type');
    }

    /**
     * Set the actor's reaction (no change when it already is $type).
     */
    public function react(Model $item, Actor $actor, string $type): string
    {
        $this->validate($type);
        $this->matrix()->guard($item, $this->name(), $actor);

        $reaction = $this->about($item)->where('actor_key', $actor->key)->first();
        $previous = $reaction?->type;

        if ($previous === $type) {
            return $type;
        }

        if ($reaction === null) {
            $reaction = $this->newQuery()->createOrFirst(
                $this->subjectKey($item) + ['actor_key' => $actor->key],
                [...$actor->attributes(), 'type' => $type],
            );
        }

        if ($reaction->type !== $type) {
            $reaction->update(['type' => $type]);
        }

        $this->refresh($item);
        $this->counters()->increment($item, [], array_filter(["reactions.{$type}" => 1, 'reactions' => $previous === null ? 1 : 0]));
        $this->matrix()->dispatch(new Reacted($item, $actor, $reaction, ['type' => $type, 'previous' => $previous]));

        return $type;
    }

    /**
     * Take the actor's reaction back; returns the type removed.
     */
    public function remove(Model $item, Actor $actor, ?string $only = null): ?string
    {
        $this->matrix()->guard($item, $this->name(), $actor);

        $reaction = $this->about($item)->where('actor_key', $actor->key)
            ->when($only, fn ($q) => $q->where('type', $only))
            ->first();

        if ($reaction === null) {
            return null;
        }

        $reaction->delete();

        $this->refresh($item);
        $this->matrix()->dispatch(new Unreacted($item, $actor, $reaction, ['type' => $reaction->type]));

        return $reaction->type;
    }

    /**
     * No reaction → add it; same type → remove it; other type → switch.
     * Returns the actor's reaction afterwards.
     */
    public function toggle(Model $item, Actor $actor, string $type): ?string
    {
        $this->validate($type);

        if ($this->current($item, $actor) === $type) {
            $this->remove($item, $actor);

            return null;
        }

        return $this->react($item, $actor, $type);
    }

    /**
     * @throws ValidationException
     */
    public function validate(string $type): void
    {
        if (! in_array($type, $this->types(), true)) {
            throw ValidationException::withMessages(['type' => __('Unknown reaction.')]);
        }
    }
}

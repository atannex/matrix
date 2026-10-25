<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Contracts\Moderatable;
use Atannex\Matrix\Contracts\ResolvesActors;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Events\Engagement;
use Atannex\Matrix\Events\Moderated;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Signals\Registry;
use Atannex\Matrix\Signals\Signal;
use Atannex\Matrix\Support\Counters;
use Atannex\Matrix\Support\Moderation;
use Atannex\Matrix\Support\Throttle;
use Atannex\Matrix\Support\Tokens;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The engagement matrix: every signal, the counters, and the rules that
 * decide who may send what to which item.
 *
 *     Matrix::for($post)->like();
 *     Matrix::for($post, $actor)->rate(4);
 *     Matrix::stats($post)->views;
 *     Matrix::extend(new PledgeSignal);
 */
final class Matrix
{
    public function __construct(
        private readonly Registry $signals,
        private readonly Counters $counters,
        private readonly ResolvesActors $actors,
        private readonly Throttle $throttle,
        private readonly Tokens $tokens,
        private readonly Moderation $moderation,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Entry points
    |--------------------------------------------------------------------------
    */

    /**
     * Engage with an item as an actor (the current one by default).
     */
    public function for(Model $item, ?Actor $actor = null): Subject
    {
        if (! $item instanceof Engageable) {
            throw new InvalidArgumentException(get_class($item).' does not implement '.Engageable::class.'.');
        }

        return new Subject($this, $item, $actor);
    }

    /**
     * The actor making the current request.
     */
    public function actor(): Actor
    {
        return $this->actors->current();
    }

    /**
     * An actor for an account outside a request.
     */
    public function actorFor(Authenticatable $user): Actor
    {
        return Actor::forUser($user);
    }

    public function stats(Model $item): Stats
    {
        return $this->counters->for($item);
    }

    /**
     * @param  iterable<Model>  $items
     * @return array<string, Stats> keyed by "{morph type}|{key}"
     */
    public function statsFor(iterable $items): array
    {
        return $this->counters->many($items);
    }

    public function token(Model $item): string
    {
        return $this->tokens->issue($item);
    }

    /*
    |--------------------------------------------------------------------------
    | Signals
    |--------------------------------------------------------------------------
    */

    /**
     * @template T of Signal
     *
     * @param  string|class-string<T>  $name
     * @return ($name is class-string<T> ? T : Signal)
     */
    public function signal(string $name): Signal
    {
        if (class_exists($name)) {
            foreach ($this->signals->all() as $signal) {
                if ($signal instanceof $name) {
                    return $signal;
                }
            }
        }

        return $this->signals->get($name);
    }

    /**
     * @return array<string, Signal>
     */
    public function signals(): array
    {
        return $this->signals->all();
    }

    /**
     * Add (or replace) a signal.
     */
    public function extend(Signal $signal): void
    {
        $this->signals->register($signal);
    }

    /**
     * Whether the item's model takes this signal at all.
     */
    public function supports(Model $item, string $signal): bool
    {
        if (! $item instanceof Engageable || ! $this->signals->has($signal)) {
            return false;
        }

        $allowed = $item->matrixSignals();

        return $allowed === null || in_array($signal, $allowed, true);
    }

    /**
     * May the actor send this signal to the item right now? Interactive
     * signals throw when not; tracking signals return false.
     *
     * @throws EngagementRefused
     */
    public function guard(Model $item, string $signal, Actor $actor, bool $ignoreClosed = false): bool
    {
        $refusal = match (true) {
            ! $this->supports($item, $signal) => EngagementRefused::unsupported($signal),
            ! $ignoreClosed && ! $item->acceptsEngagement($signal) => EngagementRefused::closed($signal),
            $actor->isGuest() && ! $this->signals->get($signal)->allowsGuests() => EngagementRefused::signIn(),
            default => null,
        };

        if ($refusal === null) {
            return true;
        }

        if ($this->signals->get($signal)->interactive()) {
            throw $refusal;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Moderation
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the actor moderates, optionally for one comment or review.
     */
    public function isModerator(?Actor $actor, ?Model $content = null): bool
    {
        return $this->moderation->isModerator($actor, $content);
    }

    /**
     * Decide who moderates, e.g. fn ($user) => $user->hasRole('editor').
     */
    public function moderatorsUsing(?Closure $callback): void
    {
        $this->moderation->moderatorsUsing($callback);
    }

    /**
     * Hold what some signed-in users write for a moderator (Moderation::holdUsing).
     */
    public function holdUsing(?Closure $callback): void
    {
        $this->moderation->holdUsing($callback);
    }

    /**
     * Set a comment's or review's status (moderators only).
     */
    public function moderate(Model&Moderatable $content, Status $status, Actor $moderator): Model
    {
        if (! $this->isModerator($moderator, $content)) {
            throw EngagementRefused::forbidden();
        }

        $content->forceFill(['status' => $status])->save();

        return $content;
    }

    /**
     * Keep the commented item's totals in step and announce moderation;
     * called by the Comment model whenever a comment changes.
     */
    public function commentsChanged(Comment $comment): void
    {
        $this->writtenContentChanged($comment, 'comment', 'comments');
    }

    public function reviewsChanged(Review $review): void
    {
        $this->writtenContentChanged($review, 'review', 'reviews');
    }

    private function writtenContentChanged(Comment|Review $content, string $signal, string $metric): void
    {
        $type = $content->getAttribute('engageable_type');
        $id = $content->getAttribute('engageable_id');

        // Forms hand ids over as numeric strings or floats.
        if (is_float($id) || (is_string($id) && ctype_digit($id))) {
            $id = (int) $id;
        }

        if ($type === null || $id === null || ! $this->signals->has($signal)) {
            return;
        }

        $this->counters->refresh($type, $id, $this->signals->get($signal));

        $live = $content->exists && ! (method_exists($content, 'trashed') && $content->trashed());
        // wasRecentlyCreated stays true for the instance's lifetime; an insert leaves no changes.
        $justCreated = $content->wasRecentlyCreated && $content->getChanges() === [];
        $statusChanged = ! $justCreated && $content->wasChanged('status');

        if ($live && $content->isApproved() && ($justCreated || $statusChanged)) {
            $this->counters->incrementKey($type, $id, [], [$metric => 1]);
        }

        if ($live && $statusChanged) {
            $from = $content->getPrevious()['status'] ?? null;

            $this->dispatch(new Moderated($content, $this->actor(), $content, [
                'from' => $from instanceof Status ? $from : Status::tryFrom((string) $from),
                'to' => $content->status,
            ]));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    */

    /**
     * Carry a guest's engagement over to the account that just signed in.
     *
     * @return int items whose totals changed
     */
    public function claim(string $guestKey, Actor $account): int
    {
        if ($account->isGuest() || $guestKey === $account->key) {
            return 0;
        }

        $affected = [];

        $this->counters->connection()->transaction(function () use ($guestKey, $account, &$affected) {
            foreach ($this->signals->all() as $signal) {
                foreach ($signal->transfer($guestKey, $account) as $item) {
                    $affected[$item['type'].'|'.$item['id']] = $item;
                }
            }
        });

        // Merged rows change unique viewers, likes and ratings: recount those items.
        foreach ($affected as $item) {
            $this->counters->recountKey($item['type'], $item['id']);
        }

        return count($affected);
    }

    /**
     * Remove everything recorded about an item (it was deleted for good).
     */
    public function forget(Model $item): void
    {
        [$type, $id] = [$item->getMorphClass(), $item->getKey()];

        foreach ($this->signals->all() as $signal) {
            $signal->forget($type, $id);
        }

        $this->counters->forget($type, $id);
    }

    public function dispatch(Engagement $event): void
    {
        // Resolved per call, so Event::fake() and late listeners see it.
        app(Dispatcher::class)->dispatch($event);
    }

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    public function counters(): Counters
    {
        return $this->counters;
    }

    public function throttle(): Throttle
    {
        return $this->throttle;
    }

    public function tokens(): Tokens
    {
        return $this->tokens;
    }

    public function moderation(): Moderation
    {
        return $this->moderation;
    }

    public function actors(): ResolvesActors
    {
        return $this->actors;
    }
}

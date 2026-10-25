<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Author;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Events\Commented;
use Atannex\Matrix\Events\CommentEdited;
use Atannex\Matrix\Events\CommentRemoved;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Signals\Concerns\ClaimsWrittenContent;
use Atannex\Matrix\Support\Moderation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Threaded comments with moderation, edit window, pinning and spam checks.
 *
 * Metrics: comments (approved comments and replies).
 */
class CommentSignal extends Signal
{
    use ClaimsWrittenContent;

    /** @var array<int|string, mixed> relations loaded with every comment */
    private array $with = ['user'];

    public function name(): string
    {
        return 'comment';
    }

    public function owns(string $metric): bool
    {
        return $metric === 'comments';
    }

    public function recount(string $type, int|string $id): array
    {
        $count = $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->where('status', Status::Approved->value)->count();

        return $count > 0 ? ['comments' => $count] : [];
    }

    /**
     * Relations to eager-load on every comment in a thread (e.g. 'user.media').
     *
     * @param  array<int|string, mixed>  $with
     */
    public function loadWith(array $with): void
    {
        $this->with = $with;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Comments on the item the actor may see.
     */
    public function visible(Model $item, ?Actor $actor): Builder
    {
        return $this->about($item)->visibleTo($actor);
    }

    /**
     * Newest top-level comments (pinned first) the actor may see, with their
     * visible replies down to "comments.max_depth", each with its author,
     * reaction totals (matrixCounters) and the actor's own reaction
     * (matrix_reaction).
     *
     * @return Collection<int, Comment>
     */
    public function thread(Model $item, ?Actor $actor, int $limit = 20, int $offset = 0): Collection
    {
        $decorate = fn (Builder|HasMany $query) => $this->decorate($query->visibleTo($actor), $actor);

        $with = [];
        $path = 'replies';

        for ($depth = 1; $depth <= $this->maxDepth(); $depth++) {
            $with[$path] = $decorate;
            $path .= '.replies';
        }

        return $this->decorate($this->visible($item, $actor)->topLevel(), $actor)
            ->with($with)
            ->pinnedFirst()
            ->latest()
            ->latest('id')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function count(Model $item, ?Actor $actor): int
    {
        return $this->visible($item, $actor)->count();
    }

    /**
     * A comment on this item the actor may see.
     *
     * @throws ModelNotFoundException
     */
    public function find(Model $item, int|string $id, ?Actor $actor): Comment
    {
        return $this->visible($item, $actor)->find($id)
            ?? throw (new ModelNotFoundException)->setModel((string) $this->model(), [$id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Why the actor may not comment right now, or null.
     */
    public function restriction(Actor $actor): ?string
    {
        return $this->moderation()->restriction('comment', $actor, $this->allowsGuests());
    }

    public function requiresModeration(Actor $actor): bool
    {
        return $this->moderation()->holdsFor('comment', $actor);
    }

    /**
     * Post a comment, or a reply to a visible comment on the same item.
     * Guests must sign it with an Author.
     *
     * @throws EngagementRefused
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function post(Model $item, Actor $actor, string $body, int|string|null $replyTo = null, ?Author $guest = null): Comment
    {
        $this->matrix()->guard($item, $this->name(), $actor);
        $this->moderation()->ensureMayWrite('comment', $actor, $this->allowsGuests());

        if ($actor->isGuest() && $guest === null) {
            throw ValidationException::withMessages(['guest_name' => __('Guests must give a name and email to comment.')]);
        }

        $body = trim($body);
        $status = $this->moderation()->verdict('comment', $actor, $body);
        $parent = $replyTo === null ? null : $this->parentFor($item, $replyTo, $actor);

        $this->ensureNotDuplicate($item, $actor, $body);

        /** @var Comment $comment */
        $comment = $this->newQuery()->create([
            ...$this->subjectKey($item),
            ...$actor->attributes(['session_id', 'user_agent'], fullIp: true),
            ...($actor->isGuest() ? $guest->attributes() : []),
            'parent_id' => $parent?->getKey(),
            'root_id' => $parent ? ($parent->root_id ?? $parent->getKey()) : null,
            'depth' => $parent ? $parent->depth + 1 : 0,
            'body' => $body,
            'status' => $status,
        ]);

        $this->matrix()->dispatch(new Commented($item, $actor, $comment, ['reply' => $parent !== null, 'status' => $status]));

        return $comment;
    }

    /**
     * Change the text: the author inside the edit window, or a moderator.
     */
    public function edit(Comment $comment, Actor $actor, string $body): Comment
    {
        $moderator = $this->moderation()->isModerator($actor, $comment);

        if (! $moderator && ! ($comment->isWrittenBy($actor) && $comment->withinEditWindow())) {
            throw EngagementRefused::forbidden();
        }

        $body = trim($body);
        $previous = $comment->body;
        $status = $this->moderation()->verdict('comment', $actor, $body);

        $comment->forceFill([
            'body' => $body,
            'edited_at' => now(),
            // An approved comment edited into something suspect goes back to the queue.
            'status' => $moderator || $status === Status::Approved ? $comment->status : $status,
        ])->save();

        if ($subject = $comment->engageable) {
            $this->matrix()->dispatch(new CommentEdited($subject, $actor, $comment, ['previous' => $previous]));
        }

        return $comment;
    }

    public function canDelete(Comment $comment, Actor $actor): bool
    {
        return $comment->isWrittenBy($actor) || $this->moderation()->isModerator($actor, $comment);
    }

    public function canEdit(Comment $comment, Actor $actor): bool
    {
        return ($comment->isWrittenBy($actor) && $comment->withinEditWindow()) || $this->moderation()->isModerator($actor, $comment);
    }

    /**
     * Delete (with its replies): the author or a moderator.
     */
    public function delete(Comment $comment, Actor $actor): void
    {
        if (! $this->canDelete($comment, $actor)) {
            throw EngagementRefused::forbidden();
        }

        $subject = $comment->engageable;

        $comment->delete();

        if ($subject) {
            $this->matrix()->dispatch(new CommentRemoved($subject, $actor, $comment));
        }
    }

    public function pin(Comment $comment, Actor $actor, bool $pinned = true): Comment
    {
        if (! $this->moderation()->isModerator($actor, $comment)) {
            throw EngagementRefused::forbidden();
        }

        $comment->forceFill(['pinned_at' => $pinned ? now() : null])->save();

        return $comment;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function parentFor(Model $item, int|string $id, Actor $actor): Comment
    {
        $parent = $this->find($item, $id, $actor);

        if (! $parent->isApproved()) {
            throw ValidationException::withMessages(['body' => __('You can reply once the comment is published.')]);
        }

        // Deeper than allowed: hang the reply off the deepest allowed comment.
        while ($parent->depth >= $this->maxDepth() && $parent->parent_id !== null) {
            $parent = $parent->parent;
        }

        return $parent;
    }

    private function ensureNotDuplicate(Model $item, Actor $actor, string $body): void
    {
        $window = (int) config('matrix.comments.duplicate_window', 0);

        if ($window <= 0) {
            return;
        }

        $duplicate = $this->about($item)
            ->where('actor_key', $actor->key)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->where('body', $body)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['body' => __('You already posted that.')]);
        }
    }

    private function decorate(Builder|HasMany $query, ?Actor $actor): Builder|HasMany
    {
        return $query
            ->with([...$this->with, 'matrixCounters'])
            ->when($actor, fn ($q) => $q->withReactionOf($actor));
    }

    private function maxDepth(): int
    {
        return max(0, (int) config('matrix.comments.max_depth', 3));
    }

    private function moderation(): Moderation
    {
        return app(Moderation::class);
    }
}

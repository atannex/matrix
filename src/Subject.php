<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Enums\ViewOutcome;
use Atannex\Matrix\Models\Bookmark;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\Follow;
use Atannex\Matrix\Models\Rating;
use Atannex\Matrix\Models\Report;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Models\Share;
use Atannex\Matrix\Signals\BookmarkSignal;
use Atannex\Matrix\Signals\ClickSignal;
use Atannex\Matrix\Signals\CommentSignal;
use Atannex\Matrix\Signals\FollowSignal;
use Atannex\Matrix\Signals\RatingSignal;
use Atannex\Matrix\Signals\ReactionSignal;
use Atannex\Matrix\Signals\ReadSignal;
use Atannex\Matrix\Signals\ReportSignal;
use Atannex\Matrix\Signals\ReviewSignal;
use Atannex\Matrix\Signals\ShareSignal;
use Atannex\Matrix\Signals\ViewSignal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One item seen through one actor: everything they can do to it, and what
 * they have done.
 *
 *     $post->matrix()->like();
 *     $post->matrix()->rate(5);
 *     $post->matrix()->comment('Great read', replyTo: 12);
 *     $post->matrix()->throttled()->share('whatsapp');
 *     $post->matrix()->as($actor)->bookmark('read-later');
 *     $post->matrix()->state();   // what the actor did, for the UI
 *     $post->matrix()->stats();   // the item's totals
 */
final class Subject
{
    private ?Actor $actor;

    private ?string $throttleField = null;

    public function __construct(
        private readonly Matrix $matrix,
        private readonly Model&Engageable $item,
        ?Actor $actor = null,
    ) {
        $this->actor = $actor;
    }

    public function item(): Model&Engageable
    {
        return $this->item;
    }

    public function actor(): Actor
    {
        return $this->actor ??= $this->matrix->actor();
    }

    /**
     * The same item, as another actor.
     */
    public function as(Actor $actor): self
    {
        return new self($this->matrix, $this->item, $actor);
    }

    /**
     * Apply config('matrix.limits') to the actions that follow; over the
     * limit, a ValidationException on $field.
     */
    public function throttled(string $field = 'matrix'): self
    {
        $clone = clone $this;
        $clone->throttleField = $field;

        return $clone;
    }

    public function supports(string $signal): bool
    {
        return $this->matrix->supports($this->item, $signal);
    }

    public function token(): string
    {
        return $this->matrix->token($this->item);
    }

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    public function view(): ViewOutcome
    {
        return $this->signal(ViewSignal::class, 'beacon')->record($this->item, $this->actor());
    }

    /**
     * Engaged reading time; $first marks the first report of a page view.
     */
    public function read(int $seconds, bool $first = true): int
    {
        return $this->signal(ReadSignal::class, 'beacon')->record($this->item, $this->actor(), $seconds, $first);
    }

    /**
     * @param  array{placement?: ?string, page?: ?string, referrer_host?: ?string}  $context
     */
    public function click(array $context = []): bool
    {
        return $this->signal(ClickSignal::class, 'beacon')->record($this->item, $this->actor(), $context);
    }

    public function share(string $platform): ?Share
    {
        return $this->signal(ShareSignal::class, 'share')->record($this->item, $this->actor(), $platform);
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    public function reaction(): ?string
    {
        return $this->signal(ReactionSignal::class)->current($this->item, $this->actor());
    }

    public function react(string $type): string
    {
        return $this->signal(ReactionSignal::class, 'reaction')->react($this->item, $this->actor(), $type);
    }

    /**
     * Returns the actor's reaction afterwards.
     */
    public function toggleReaction(string $type): ?string
    {
        return $this->signal(ReactionSignal::class, 'reaction')->toggle($this->item, $this->actor(), $type);
    }

    public function unreact(): ?string
    {
        return $this->signal(ReactionSignal::class, 'reaction')->remove($this->item, $this->actor());
    }

    public function like(): string
    {
        return $this->react('like');
    }

    public function unlike(): bool
    {
        return $this->signal(ReactionSignal::class, 'reaction')->remove($this->item, $this->actor(), 'like') !== null;
    }

    public function toggleLike(): bool
    {
        return $this->toggleReaction('like') === 'like';
    }

    public function dislike(): string
    {
        return $this->react('dislike');
    }

    public function undislike(): bool
    {
        return $this->signal(ReactionSignal::class, 'reaction')->remove($this->item, $this->actor(), 'dislike') !== null;
    }

    public function toggleDislike(): bool
    {
        return $this->toggleReaction('dislike') === 'dislike';
    }

    public function liked(): bool
    {
        return $this->reaction() === 'like';
    }

    public function disliked(): bool
    {
        return $this->reaction() === 'dislike';
    }

    /*
    |--------------------------------------------------------------------------
    | Ratings
    |--------------------------------------------------------------------------
    */

    /**
     * Returns the actor's score afterwards (null when it was taken back).
     */
    public function rate(int $value, string $dimension = Rating::OVERALL, ?string $comment = null): ?int
    {
        return $this->signal(RatingSignal::class, 'rating')->rate($this->item, $this->actor(), $value, $dimension, $comment);
    }

    public function unrate(string $dimension = Rating::OVERALL): ?int
    {
        return $this->signal(RatingSignal::class, 'rating')->unrate($this->item, $this->actor(), $dimension);
    }

    public function rating(string $dimension = Rating::OVERALL): ?int
    {
        return $this->signal(RatingSignal::class)->current($this->item, $this->actor(), $dimension);
    }

    /**
     * @return array<string, int> dimension => score
     */
    public function ratings(): array
    {
        return $this->signal(RatingSignal::class)->all($this->item, $this->actor());
    }

    /*
    |--------------------------------------------------------------------------
    | Reviews
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $meta
     */
    public function review(string $body, ?int $rating = null, ?string $title = null, ?Author $guest = null, array $meta = []): Review
    {
        return $this->signal(ReviewSignal::class, 'review')->write($this->item, $this->actor(), $body, $rating, $title, $guest, $meta);
    }

    public function myReview(): ?Review
    {
        return $this->signal(ReviewSignal::class)->current($this->item, $this->actor());
    }

    public function unreview(): bool
    {
        $review = $this->myReview();

        if ($review === null) {
            return false;
        }

        $this->signal(ReviewSignal::class)->delete($review, $this->actor());

        return true;
    }

    public function reviews(int $perPage = 10, string $sort = 'helpful'): LengthAwarePaginator
    {
        return $this->signal(ReviewSignal::class)->list($this->item, $this->actor(), $perPage, $sort);
    }

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    */

    public function comment(string $body, int|string|null $replyTo = null, ?Author $guest = null): Comment
    {
        return $this->signal(CommentSignal::class, 'comment')->post($this->item, $this->actor(), $body, $replyTo, $guest);
    }

    /**
     * @return Collection<int, Comment>
     */
    public function comments(int $limit = 20, int $offset = 0): Collection
    {
        return $this->signal(CommentSignal::class)->thread($this->item, $this->actor(), $limit, $offset);
    }

    public function commentCount(): int
    {
        return $this->signal(CommentSignal::class)->count($this->item, $this->actor());
    }

    public function findComment(int|string $id): Comment
    {
        return $this->signal(CommentSignal::class)->find($this->item, $id, $this->actor());
    }

    public function editComment(int|string $id, string $body): Comment
    {
        return $this->signal(CommentSignal::class, 'comment')->edit($this->findComment($id), $this->actor(), $body);
    }

    public function deleteComment(int|string $id): void
    {
        $this->signal(CommentSignal::class)->delete($this->findComment($id), $this->actor());
    }

    /**
     * Like or unlike a comment on this item; true when it is now liked.
     */
    public function toggleCommentLike(int|string $id): bool
    {
        return $this->findComment($id)->matrix($this->actor())->throttledIf($this->throttleField)->toggleLike();
    }

    /**
     * Why the actor may not comment right now, or null.
     */
    public function commentRestriction(): ?string
    {
        if (! $this->supports('comment')) {
            return __('Comments are not available here.');
        }

        if (! $this->item->acceptsEngagement('comment')) {
            return __('Comments are closed.');
        }

        return $this->signal(CommentSignal::class)->restriction($this->actor());
    }

    /*
    |--------------------------------------------------------------------------
    | Bookmarks & follows
    |--------------------------------------------------------------------------
    */

    public function bookmark(string $collection = Bookmark::DEFAULT_COLLECTION, ?string $note = null): Bookmark
    {
        return $this->signal(BookmarkSignal::class, 'bookmark')->add($this->item, $this->actor(), $collection, $note);
    }

    public function unbookmark(?string $collection = null): bool
    {
        return $this->signal(BookmarkSignal::class, 'bookmark')->remove($this->item, $this->actor(), $collection);
    }

    public function toggleBookmark(string $collection = Bookmark::DEFAULT_COLLECTION): bool
    {
        return $this->signal(BookmarkSignal::class, 'bookmark')->toggle($this->item, $this->actor(), $collection);
    }

    public function bookmarked(?string $collection = null): bool
    {
        return $this->signal(BookmarkSignal::class)->has($this->item, $this->actor(), $collection);
    }

    public function follow(bool $notify = true): Follow
    {
        return $this->signal(FollowSignal::class, 'follow')->follow($this->item, $this->actor(), $notify);
    }

    public function unfollow(): bool
    {
        return $this->signal(FollowSignal::class, 'follow')->unfollow($this->item, $this->actor());
    }

    public function toggleFollow(): bool
    {
        return $this->signal(FollowSignal::class, 'follow')->toggle($this->item, $this->actor());
    }

    public function following(): bool
    {
        return $this->signal(FollowSignal::class)->has($this->item, $this->actor());
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    public function report(string $reason, ?string $details = null): Report
    {
        return $this->signal(ReportSignal::class, 'report')->report($this->item, $this->actor(), $reason, $details);
    }

    public function reported(): bool
    {
        return $this->signal(ReportSignal::class)->has($this->item, $this->actor());
    }

    /*
    |--------------------------------------------------------------------------
    | Totals & state
    |--------------------------------------------------------------------------
    */

    public function stats(): Stats
    {
        return $this->matrix->stats($this->item);
    }

    /**
     * Everything the actor has done to the item, for rendering buttons. Only
     * signals the model takes are looked up.
     *
     * @return array{reaction: ?string, ratings: array<string, int>, review: ?int, bookmarked: bool, following: bool, reported: bool}
     */
    public function state(): array
    {
        $has = fn (string $signal) => $this->supports($signal);

        return [
            'reaction' => $has('reaction') ? $this->reaction() : null,
            'ratings' => $has('rating') ? $this->ratings() : [],
            'review' => $has('review') ? $this->myReview()?->getKey() : null,
            'bookmarked' => $has('bookmark') && $this->bookmarked(),
            'following' => $has('follow') && $this->following(),
            'reported' => $has('report') && $this->reported(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @internal used to pass throttling on to a nested subject
     */
    public function throttledIf(?string $field): self
    {
        return $field === null ? $this : $this->throttled($field);
    }

    /**
     * @template T of Signals\Signal
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function signal(string $class, ?string $throttle = null): Signals\Signal
    {
        if ($throttle !== null && $this->throttleField !== null) {
            $this->matrix->throttle()->ensure($throttle, $this->actor(), $this->throttleField);
        }

        return $this->matrix->signal($class);
    }
}

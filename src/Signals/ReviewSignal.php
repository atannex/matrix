<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Author;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Events\Reviewed;
use Atannex\Matrix\Events\ReviewRemoved;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Signals\Concerns\ClaimsWrittenContent;
use Atannex\Matrix\Support\Moderation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Written reviews with an optional score: one per actor per item, editable
 * by the author inside the edit window, moderated like comments.
 *
 * Metrics: reviews (approved), reviews_rated, reviews_rating_sum.
 */
class ReviewSignal extends Signal
{
    use ClaimsWrittenContent;

    public function name(): string
    {
        return 'review';
    }

    public function owns(string $metric): bool
    {
        return in_array($metric, ['reviews', 'reviews_rated', 'reviews_rating_sum'], true);
    }

    public function recount(string $type, int|string $id): array
    {
        $row = $this->base()
            ->where('engageable_type', $type)
            ->where('engageable_id', $id)
            ->where('status', Status::Approved->value)
            ->whereNull('deleted_at')
            ->selectRaw('count(*) as reviews, count(rating) as rated, coalesce(sum(rating), 0) as rating_sum')
            ->first();

        return array_filter([
            'reviews' => (int) $row->reviews,
            'reviews_rated' => (int) $row->rated,
            'reviews_rating_sum' => (int) $row->rating_sum,
        ]);
    }

    public function current(Model $item, Actor $actor): ?Review
    {
        return $this->about($item)->where('actor_key', $actor->key)->latest()->first();
    }

    /**
     * Approved reviews (plus the actor's own pending one), most helpful first.
     */
    public function list(Model $item, ?Actor $actor, int $perPage = 10, string $sort = 'helpful'): LengthAwarePaginator
    {
        $model = new ($this->model());

        return $this->about($item)
            ->visibleTo($actor)
            ->with(['user', 'matrixCounters'])
            ->when($actor, fn (Builder $q) => $q->withReactionOf($actor))
            ->when($sort === 'helpful', fn (Builder $q) => $q->orderByMatrix('reactions.helpful'))
            ->when($sort === 'highest', fn (Builder $q) => $q->orderByDesc('rating'))
            ->when($sort === 'lowest', fn (Builder $q) => $q->orderBy('rating'))
            ->latest($model->qualifyColumn('created_at'))
            ->paginate($perPage);
    }

    /**
     * Write the actor's review, or update it while it is still editable.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws EngagementRefused
     * @throws ValidationException
     */
    public function write(Model $item, Actor $actor, string $body, ?int $rating = null, ?string $title = null, ?Author $guest = null, array $meta = []): Review
    {
        $this->matrix()->guard($item, $this->name(), $actor);
        $this->moderation()->ensureMayWrite('review', $actor, $this->allowsGuests());

        if ($actor->isGuest() && $guest === null) {
            throw ValidationException::withMessages(['guest_name' => __('Guests must give a name and email to review.')]);
        }

        if ($rating !== null) {
            (new RatingSignal)->validate($rating, field: 'rating');
        }

        if ($existing = $this->current($item, $actor)) {
            if (! $existing->withinEditWindow()) {
                throw ValidationException::withMessages(['body' => __('You have already reviewed this.')]);
            }

            return $this->edit($existing, $actor, $body, $rating, $title, $meta);
        }

        $body = trim($body);

        /** @var Review $review */
        $review = $this->newQuery()->create([
            ...$this->subjectKey($item),
            ...$actor->attributes(['session_id', 'user_agent'], fullIp: true),
            ...($actor->isGuest() ? $guest->attributes() : []),
            'title' => $this->title($title),
            'body' => $body,
            'rating' => $rating,
            'meta' => $meta === [] ? null : $meta,
            'status' => $this->moderation()->verdict('review', $actor, $body),
        ]);

        $this->matrix()->dispatch(new Reviewed($item, $actor, $review, ['updated' => false]));

        return $review;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function edit(Review $review, Actor $actor, string $body, ?int $rating = null, ?string $title = null, ?array $meta = null): Review
    {
        $moderator = $this->moderation()->isModerator($actor, $review);

        if (! $moderator && ! ($review->isWrittenBy($actor) && $review->withinEditWindow())) {
            throw EngagementRefused::forbidden();
        }

        $body = trim($body);
        $status = $this->moderation()->verdict('review', $actor, $body);

        $review->forceFill([
            'title' => $this->title($title),
            'body' => $body,
            'rating' => $rating,
            'edited_at' => now(),
            'status' => $moderator || $status === Status::Approved ? $review->status : $status,
        ] + ($meta === null ? [] : ['meta' => $meta]))->save();

        if ($subject = $review->engageable) {
            $this->matrix()->dispatch(new Reviewed($subject, $actor, $review, ['updated' => true]));
        }

        return $review;
    }

    public function delete(Review $review, Actor $actor): void
    {
        if (! $review->isWrittenBy($actor) && ! $this->moderation()->isModerator($actor, $review)) {
            throw EngagementRefused::forbidden();
        }

        $subject = $review->engageable;

        $review->delete();

        if ($subject) {
            $this->matrix()->dispatch(new ReviewRemoved($subject, $actor, $review));
        }
    }

    private function title(?string $title): ?string
    {
        $title = $title === null ? null : trim($title);

        return $title === '' || $title === null ? null : mb_substr($title, 0, 255);
    }

    private function moderation(): Moderation
    {
        return app(Moderation::class);
    }
}

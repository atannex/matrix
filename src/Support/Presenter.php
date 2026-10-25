<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Matrix;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Signals\CommentSignal;
use Atannex\Matrix\Subject;

/**
 * Public JSON shapes for the API. Never includes emails, IPs or actor keys.
 */
final class Presenter
{
    public function __construct(
        private readonly Matrix $matrix,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function subject(Subject $subject): array
    {
        $stats = $subject->stats();

        return [
            'stats' => $stats->toArray(),
            'reactions' => $stats->reactionBreakdown(),
            'state' => $subject->state(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(Comment $comment, ?Actor $actor): array
    {
        $comments = $this->matrix->signal(CommentSignal::class);
        $stats = $comment->matrixStats();

        return [
            'id' => $comment->getKey(),
            'parent_id' => $comment->parent_id,
            'depth' => $comment->depth,
            'author' => $comment->authorName(),
            'body' => $comment->body,
            'status' => $comment->status->value,
            'pending' => $comment->isPending(),
            'edited' => $comment->isEdited(),
            'pinned' => $comment->isPinned(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'reactions' => $stats->reactionBreakdown(),
            'likes' => $stats->likes(),
            'my_reaction' => $comment->getAttribute('matrix_reaction'),
            'mine' => $actor !== null && $comment->isWrittenBy($actor),
            'can_edit' => $actor !== null && $comments->canEdit($comment, $actor),
            'can_delete' => $actor !== null && $comments->canDelete($comment, $actor),
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (Comment $reply) => $this->comment($reply, $actor))->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function review(Review $review, ?Actor $actor): array
    {
        $stats = $review->matrixStats();

        return [
            'id' => $review->getKey(),
            'author' => $review->authorName(),
            'title' => $review->title,
            'body' => $review->body,
            'rating' => $review->rating,
            'status' => $review->status->value,
            'pending' => $review->isPending(),
            'edited' => $review->isEdited(),
            'created_at' => $review->created_at?->toIso8601String(),
            'helpful' => $stats->reaction('helpful'),
            'my_reaction' => $review->getAttribute('matrix_reaction'),
            'mine' => $actor !== null && $review->isWrittenBy($actor),
        ];
    }
}

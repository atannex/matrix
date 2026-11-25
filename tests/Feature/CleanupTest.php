<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\Counter;
use Atannex\Matrix\Models\DailyCounter;
use Atannex\Matrix\Models\Reaction;
use Atannex\Matrix\Models\Report;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Tests\TestCase;

class CleanupTest extends TestCase
{
    public function test_deleting_a_comment_forgets_the_engagement_of_its_replies(): void
    {
        $article = $this->article();
        $author = $this->account();

        $top = $article->matrix($author)->comment('Top comment');
        $reply = $article->matrix($author)->comment('A reply here', replyTo: $top->id);
        $nested = $article->matrix($author)->comment('Deeper reply', replyTo: $reply->id);

        $reader = $this->account($this->user('Reader'));
        $reply->matrix($reader)->like();
        $nested->matrix($reader)->like();
        $nested->matrix($reader)->report('spam');

        $article->matrix($author)->deleteComment($top->id);

        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, Reaction::query()->count());
        $this->assertSame(0, Report::query()->count());
        $this->assertSame(0, Counter::query()->where('engageable_type', $top->getMorphClass())->count());
        $this->assertSame(0, DailyCounter::query()->where('engageable_type', $top->getMorphClass())->count());
        $this->assertSame(0, $article->fresh()->matrixStats()->comments);
    }

    public function test_deleting_an_item_forgets_the_engagement_of_its_comments_and_reviews(): void
    {
        $article = $this->article();
        $author = $this->account();
        $reader = $this->account($this->user('Reader'));

        $comment = $article->matrix($author)->comment('Nice story');
        $review = $article->matrix($author)->review('A thorough and fair piece.', 5);
        $comment->matrix($reader)->like();
        $review->matrix($reader)->react('helpful');

        $article->forceDelete();

        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, Review::withTrashed()->count());
        $this->assertSame(0, Reaction::query()->count());
        $this->assertSame(0, Counter::query()->count());
        $this->assertSame(0, DailyCounter::query()->count());
    }

    public function test_deleting_a_user_keeps_the_totals_in_step(): void
    {
        $article = $this->article();
        $leaver = $this->user('Leaver');
        $stayer = $this->account($this->user('Stayer'));
        $account = $this->account($leaver);

        $article->matrix($account)->like();
        $article->matrix($account)->bookmark();
        $article->matrix($account)->follow();
        $article->matrix($stayer)->like();

        $comment = $article->matrix($account)->comment('Leaving soon');
        $reply = $article->matrix($stayer)->comment('Sad to see', replyTo: $comment->id);
        $reply->matrix($stayer)->like();
        $article->matrix($account)->review('A thorough and fair piece.', 5);

        $leaver->delete();

        $stats = $article->fresh()->matrixStats();
        $this->assertSame(1, $stats->reactions);
        $this->assertSame(0, $stats->bookmarks);
        $this->assertSame(0, $stats->followers);
        $this->assertSame(0, $stats->comments);
        $this->assertSame(0, $stats->reviews);
        $this->assertSame(1, Reaction::query()->count(), 'Only the stayer\'s like on the article remains.');
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, Review::withTrashed()->count());
        $this->assertSame(0, Counter::query()->where('engageable_type', $comment->getMorphClass())->count());
    }
}

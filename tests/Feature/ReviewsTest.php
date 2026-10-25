<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Tests\Fixtures\User;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class ReviewsTest extends TestCase
{
    public function test_one_review_per_actor_editable_inside_the_window(): void
    {
        $article = $this->article();
        $actor = $this->account();

        $review = $article->matrix($actor)->review('A thorough, balanced piece.', 4, 'Solid');
        $again = $article->matrix($actor)->review('A thorough, balanced piece. Updated.', 5);

        $this->assertSame($review->id, $again->id);
        $this->assertSame(5, $again->rating);
        $this->assertTrue($again->isEdited());

        $stats = $article->matrixStats();
        $this->assertSame(1, $stats->reviews);
        $this->assertSame(5.0, $stats->averageReviewRating());

        $this->travel(20)->minutes();
        $this->expectException(ValidationException::class);
        $article->matrix($actor)->review('Third time around, still good.');
    }

    public function test_helpful_votes_rank_reviews(): void
    {
        $article = $this->article();
        $first = $article->matrix($this->account($this->user('A')))->review('First review here.', 3);
        $second = $article->matrix($this->account($this->user('B')))->review('Second review here.', 5);

        $second->matrix($this->guest())->react('helpful');
        $second->matrix($this->guest())->react('helpful');
        $first->matrix($this->guest())->react('helpful');

        $this->assertSame([$second->id, $first->id], $article->matrix($this->guest())->reviews()->pluck('id')->all());
        $this->assertSame(2, $second->helpfulCount());
    }

    public function test_deleting_and_moderating_updates_totals(): void
    {
        $article = $this->article();
        $actor = $this->account();
        $review = $article->matrix($actor)->review('Worth the read, overall.', 4);

        Matrix::moderate($review, Status::Rejected, $this->account($this->user('Mod', moderator: true)));
        $this->assertSame(0, $article->matrixStats()->reviews);

        Matrix::moderate($review, Status::Approved, $this->account(User::query()->where('name', 'Mod')->first()));
        $this->assertSame(1, $article->matrixStats()->reviews);

        $this->assertTrue($article->matrix($actor)->unreview());
        $this->assertSame(0, $article->matrixStats()->reviews);
    }
}

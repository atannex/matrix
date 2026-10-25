<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class RatingsTest extends TestCase
{
    public function test_ratings_average_histogram_and_toggle(): void
    {
        $article = $this->article();

        $article->matrix($this->guest())->rate(5);
        $article->matrix($this->guest())->rate(4);
        $actor = $this->guest();
        $article->matrix($actor)->rate(3);
        $article->matrix($actor)->rate(4); // change

        $stats = $article->matrixStats();
        $this->assertSame(3, $stats->ratingsCount());
        $this->assertSame(4.33, $stats->averageRating());
        $this->assertSame(['5' => 33, '4' => 67, '3' => 0, '2' => 0, '1' => 0], array_combine(array_map('strval', array_keys($stats->ratingHistogram())), $stats->ratingHistogram()));
        $this->assertSame(3.5, $stats->bayesianRating()); // (5*3 + 13) / 8

        $this->assertNull($article->matrix($actor)->rate(4)); // same score → taken back
        $this->assertSame(2, $article->matrixStats()->ratingsCount());
    }

    public function test_dimensions_are_counted_separately(): void
    {
        config(['matrix.ratings.dimensions' => ['accuracy', 'clarity']]);
        $article = $this->article();
        $actor = $this->account();

        $article->matrix($actor)->rate(5);
        $article->matrix($actor)->rate(2, 'accuracy');

        $stats = $article->matrixStats();
        $this->assertSame(5.0, $stats->averageRating());
        $this->assertSame(2.0, $stats->averageRating('accuracy'));
        $this->assertEquals(['overall' => 5, 'accuracy' => 2], $article->matrix($actor)->ratings());

        $this->expectException(ValidationException::class);
        $article->matrix($actor)->rate(3, 'vibes');
    }

    public function test_scores_out_of_range_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->article()->matrix($this->guest())->rate(6);
    }
}

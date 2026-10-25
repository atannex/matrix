<?php

namespace Atannex\Matrix\Tests\Unit;

use Atannex\Matrix\Stats;
use Atannex\Matrix\Tests\TestCase;

class StatsTest extends TestCase
{
    public function test_derived_scores(): void
    {
        $stats = new Stats(['reactions.like' => 90, 'reactions.dislike' => 10, 'views' => 100, 'comments' => 5]);

        $this->assertEqualsWithDelta(0.8256, $stats->approval(), 0.001);
        $this->assertSame(0.0, (new Stats)->approval());
        $this->assertSame(100.0 + 15.0, $stats->score(['views' => 1, 'comments' => 3]));
        $this->assertSame(0, $stats->shares);
        $this->assertSame(90, $stats['reactions.like']);
    }

    public function test_hotness_decays_with_age(): void
    {
        $stats = new Stats(['views' => 100]);

        $this->assertGreaterThan($stats->hotness(now()->subDay()), $stats->hotness(now()->subHour()));
    }
}

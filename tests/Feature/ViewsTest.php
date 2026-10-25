<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Enums\ViewOutcome;
use Atannex\Matrix\Models\DailyCounter;
use Atannex\Matrix\Models\View;
use Atannex\Matrix\Tests\TestCase;

class ViewsTest extends TestCase
{
    public function test_views_are_deduplicated_inside_the_window(): void
    {
        $article = $this->article();
        $actor = $this->guest();

        $this->assertSame(ViewOutcome::FirstView, $article->matrix($actor)->view());
        $this->assertSame(ViewOutcome::Ignored, $article->matrix($actor)->view());

        $this->travel(31)->minutes();
        $this->assertSame(ViewOutcome::Repeat, $article->matrix($actor)->view());

        $this->travel(1)->days();
        $this->assertSame(ViewOutcome::FirstToday, $article->matrix($actor)->view());

        $stats = $article->matrixStats();
        $this->assertSame(3, $stats->views);
        $this->assertSame(1, $stats->unique_viewers);
        $this->assertSame(2, (int) DailyCounter::query()->metric('unique_viewers')->sum('value'));
    }

    public function test_bots_and_closed_items_are_not_counted(): void
    {
        $bot = new Actor('guest_bot', null, null, '1.1.1.1', 'Googlebot/2.1');

        $this->assertSame(ViewOutcome::Ignored, $this->article()->matrix($bot)->view());
        $this->assertSame(ViewOutcome::Ignored, $this->article(['published' => false])->matrix($this->guest())->view());
    }

    public function test_reading_time_and_clicks(): void
    {
        $article = $this->article();
        $actor = $this->guest();

        $article->matrix($actor)->read(40);
        $article->matrix($actor)->read(20, first: false);
        $article->matrix($actor)->read(99999); // capped

        $this->assertTrue($article->matrix($actor)->click(['placement' => 'hero']));
        $this->assertFalse($article->matrix($actor)->click(['placement' => 'hero'])); // debounced

        $stats = $article->matrixStats();
        $this->assertSame(2, $stats->reads);
        $this->assertSame(60 + 1800, $stats->read_seconds);
        $this->assertSame(930, $stats->averageReadSeconds());
        $this->assertSame(1, $stats->get('clicks.hero'));
    }

    public function test_ip_addresses_are_anonymised(): void
    {
        $this->article()->matrix($this->guest())->view();

        $this->assertSame('198.51.100.0', View::query()->value('ip_address'));
    }
}

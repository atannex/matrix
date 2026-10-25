<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Contracts\MatrixEvent;
use Atannex\Matrix\Events\Reacted;
use Atannex\Matrix\Events\Unreacted;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Models\DailyCounter;
use Atannex\Matrix\Tests\Fixtures\Author;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ReactionsTest extends TestCase
{
    public function test_like_unlike_and_switch_keep_one_reaction_per_actor(): void
    {
        $article = $this->article();
        $ada = $this->account();
        $guest = $this->guest();

        $article->matrix($ada)->like();
        $article->matrix($ada)->like(); // idempotent
        $article->matrix($guest)->dislike();

        $stats = $article->matrixStats();
        $this->assertSame(1, $stats->likes());
        $this->assertSame(1, $stats->dislikes());
        $this->assertSame(2, $stats->reactions);
        $this->assertTrue($article->matrix($ada)->liked());

        $article->matrix($ada)->react('love');
        $stats = $article->matrixStats();
        $this->assertSame(0, $stats->likes());
        $this->assertSame(1, $stats->reaction('love'));
        $this->assertEquals(['love' => 1, 'dislike' => 1], $stats->reactionBreakdown());

        $this->assertTrue($article->matrix($guest)->undislike());
        $this->assertSame(0, $article->matrixStats()->dislikes());
        $this->assertFalse($article->matrixStats()->has('reactions.dislike'));
    }

    public function test_toggle_takes_the_same_reaction_back(): void
    {
        $article = $this->article();
        $actor = $this->guest();

        $this->assertTrue($article->matrix($actor)->toggleLike());
        $this->assertFalse($article->matrix($actor)->toggleLike());
        $this->assertSame(0, $article->matrixStats()->likes());
        $this->assertNull($article->matrix($actor)->reaction());
    }

    public function test_events_and_daily_rollups(): void
    {
        Event::fake([Reacted::class, Unreacted::class]);
        $article = $this->article();
        $actor = $this->guest();

        $article->matrix($actor)->like();
        $article->matrix($actor)->unlike();

        Event::assertDispatched(Reacted::class, fn (Reacted $e) => $e->context['type'] === 'like' && $e->positive() && $e instanceof MatrixEvent);
        Event::assertDispatched(Unreacted::class, fn (Unreacted $e) => ! $e->positive());
        $this->assertSame(1, (int) DailyCounter::query()->metric('reactions.like')->sum('value'));
    }

    public function test_unknown_reactions_and_closed_items_are_refused(): void
    {
        $article = $this->article();

        try {
            $article->matrix($this->guest())->react('meh');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }

        $draft = $this->article(['published' => false]);
        $this->expectException(EngagementRefused::class);
        $draft->matrix($this->guest())->like();
    }

    public function test_guests_can_be_shut_out_per_signal(): void
    {
        config(['matrix.signals.reaction.guests' => false]);

        try {
            $this->article()->matrix($this->guest())->like();
            $this->fail('Guests should be refused.');
        } catch (EngagementRefused $e) {
            $this->assertSame(EngagementRefused::SIGN_IN, $e->reason);
        }
    }

    public function test_models_only_take_their_signals(): void
    {
        $writer = Author::query()->create(['name' => 'Chinua']);

        $this->assertTrue($writer->matrix($this->account())->follow()->exists);

        $this->expectException(EngagementRefused::class);
        $writer->matrix($this->account($this->user('Bo')))->like();
    }
}

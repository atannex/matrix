<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Enums\ReportStatus;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Tests\Fixtures\Article;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class SocialTest extends TestCase
{
    public function test_shares_are_counted_per_platform(): void
    {
        $article = $this->article();
        $actor = $this->guest();

        $article->matrix($actor)->share('WhatsApp');
        $article->matrix($actor)->share('whatsapp');
        $article->matrix($this->guest())->share('x');

        $stats = $article->matrixStats();
        $this->assertSame(3, $stats->shares);
        $this->assertSame(['whatsapp' => 2, 'x' => 1], $stats->shareBreakdown());

        $this->expectException(ValidationException::class);
        $article->matrix($actor)->share('<script>');
    }

    public function test_bookmarks_in_collections(): void
    {
        $article = $this->article();
        $other = $this->article();
        $actor = $this->account();

        $article->matrix($actor)->bookmark();
        $article->matrix($actor)->bookmark('weekend');
        $other->matrix($actor)->bookmark('weekend');

        $this->assertSame(1, $article->matrixStats()->bookmarks); // one person, two lists
        $this->assertSame(['default' => 1, 'weekend' => 2], Matrix::signal('bookmark')->collections($actor)->all());
        $this->assertEqualsCanonicalizing([$article->id, $other->id], Article::whereEngagedBy($actor, 'bookmark', ['collection' => 'weekend'])->pluck('id')->all());

        $this->assertFalse($article->matrix($actor)->toggleBookmark('weekend'));
        $this->assertTrue($article->matrix($actor)->bookmarked());
        $article->matrix($actor)->unbookmark();
        $this->assertSame(0, $article->matrixStats()->bookmarks);
    }

    public function test_follows_need_an_account_by_default(): void
    {
        $article = $this->article();

        $this->assertTrue($article->matrix($this->account())->toggleFollow());
        $this->assertSame(1, $article->matrixStats()->followers);

        $this->expectException(EngagementRefused::class);
        $article->matrix($this->guest())->follow();
    }

    public function test_reports_hide_content_past_the_threshold(): void
    {
        config(['matrix.reports.hide_threshold' => 2]);
        $article = $this->article();
        $comment = $article->matrix($this->account())->comment('Something rude');

        $comment->matrix($this->guest())->report('abuse');
        $this->assertSame(Status::Approved, $comment->fresh()->status);

        $comment->matrix($this->guest())->report('abuse', 'Personal attack');
        $this->assertSame(Status::Pending, $comment->fresh()->status);
        $this->assertSame(2, $comment->matrixStats()->reports);
        $this->assertSame(0, $article->matrixStats()->comments);

        $moderator = $this->account($this->user('Mod', moderator: true));
        foreach ($comment->reports as $report) {
            Matrix::signal('report')->resolve($report, $moderator, ReportStatus::Dismissed);
        }
        $this->assertSame(0, $comment->fresh()->matrixStats()->reports);

        $this->expectException(ValidationException::class);
        $article->matrix($this->guest())->report('not-a-reason');
    }

    public function test_state_summarises_what_the_actor_did(): void
    {
        $article = $this->article();
        $actor = $this->account();

        $article->matrix($actor)->like();
        $article->matrix($actor)->rate(4);
        $article->matrix($actor)->bookmark();

        $state = $article->matrix($actor)->state();

        $this->assertSame('like', $state['reaction']);
        $this->assertSame(['overall' => 4], $state['ratings']);
        $this->assertTrue($state['bookmarked']);
        $this->assertFalse($state['following']);
    }
}

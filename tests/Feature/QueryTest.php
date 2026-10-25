<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Models\Counter;
use Atannex\Matrix\Models\Reaction;
use Atannex\Matrix\Tests\Fixtures\Article;
use Atannex\Matrix\Tests\TestCase;

class QueryTest extends TestCase
{
    public function test_ordering_selecting_and_filtering_by_totals(): void
    {
        [$quiet, $popular, $middle] = [$this->article(), $this->article(), $this->article()];

        foreach (range(1, 3) as $i) {
            $popular->matrix($this->guest())->view();
        }
        $middle->matrix($this->guest())->view();
        $popular->matrix($this->guest())->like();

        $this->assertSame([$popular->id, $middle->id, $quiet->id], Article::orderByMatrix('views')->pluck('id')->all());

        $rows = Article::withMatrix('views', 'reactions.like')->orderBy('id')->get();
        $this->assertSame([0, 3, 1], $rows->pluck('matrix_views')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, (int) $rows[1]->matrix_reactions_like);

        $this->assertSame([$popular->id], Article::whereMatrix('views', '>=', 2)->pluck('id')->all());
    }

    public function test_all_time_totals_per_type_and_for_some_items(): void
    {
        [$a, $b] = [$this->article(), $this->article()];

        $a->matrix($this->guest())->view();
        $a->matrix($this->guest())->view();
        $b->matrix($this->guest())->view();
        $b->matrix($this->guest())->like();

        $type = $a->getMorphClass();

        $this->assertSame(['views' => 3, 'reactions' => 1], Matrix::counters()->totals(['views', 'reactions'], $type));
        $this->assertSame(['views' => 2], Matrix::counters()->totals(['views'], $type, [$a->id]));
        $this->assertSame(['views' => 1], Matrix::counters()->totals(['views'], $type, Article::whereKey($b->id)->select('id')));
        $this->assertSame(['shares' => 0], Matrix::counters()->totals(['shares']));
    }

    public function test_breakdown_of_a_metric_over_a_window(): void
    {
        $article = $this->article();

        $article->matrix($this->guest())->share('whatsapp');
        $article->matrix($this->guest())->share('whatsapp');
        $article->matrix($this->guest())->share('x');
        $article->matrix($this->guest())->like(); // reactions.like: not a share

        $this->assertSame(['whatsapp' => 2, 'x' => 1], Matrix::counters()->breakdownBetween(now()->subDay(), now(), 'shares'));
        $this->assertSame([], Matrix::counters()->breakdownBetween(now()->subDays(9), now()->subDays(8), 'shares'));
    }

    public function test_trending_and_windows_use_the_daily_rollups(): void
    {
        $old = $this->article();
        $this->travel(-10)->days();
        foreach (range(1, 5) as $i) {
            $old->matrix($this->guest())->view();
        }
        $this->travelBack();

        $fresh = $this->article();
        $fresh->matrix($this->guest())->view();
        $fresh->matrix($this->account())->comment('Fresh take');

        $this->assertSame($fresh->id, Article::trending(now()->subDays(7))->first()->id);
        $this->assertSame($old->id, Article::orderByMatrix('views')->first()->id);
        $this->assertSame([$fresh->id], Article::whereMatrixSince('views', now()->subDays(7))->pluck('id')->all());
        $this->assertSame(5, (int) Article::withMatrixSince('views', now()->subDays(30))->find($old->id)->matrix_views_since);
    }

    public function test_all_time_score_and_average_rating_subqueries(): void
    {
        [$viewed, $liked] = [$this->article(), $this->article()];

        foreach (range(1, 3) as $i) {
            $viewed->matrix($this->guest())->view();
        }
        $liked->matrix($this->guest())->like();
        $liked->matrix($this->guest())->like();
        $liked->matrix($this->guest())->rate(5);
        $liked->matrix($this->guest())->rate(2);

        // views × 1 = 3 against likes × 2 = 4 (config score.weights).
        config(['matrix.score.weights' => ['views' => 1, 'reactions.like' => 2]]);
        $this->assertSame([$liked->id, $viewed->id], Article::query()->orderByDesc(Article::matrixScore())->pluck('id')->all());

        $rows = Article::query()->addSelect(['*', 'avg' => Article::matrixAverageRating()])->orderBy('id')->get();
        $this->assertEqualsWithDelta(0.0, (float) $rows[0]->avg, 0.001, 'Unrated items average 0.');
        $this->assertEqualsWithDelta(3.5, (float) $rows[1]->avg, 0.001);
    }

    public function test_recount_fixes_drift_and_keeps_counter_only_metrics(): void
    {
        $article = $this->article();
        $article->matrix($this->guest())->view();
        $article->matrix($this->guest())->like();
        $article->matrix($this->guest())->read(30);

        Counter::query()->where('metric', 'views')->update(['value' => 999]);
        Counter::query()->create(['engageable_type' => $article->getMorphClass(), 'engageable_id' => $article->id, 'metric' => 'reactions.wow', 'value' => 4]);

        $this->assertSame(1, Matrix::counters()->rebuild());

        $stats = $article->matrixStats();
        $this->assertSame(1, $stats->views);
        $this->assertSame(0, $stats->reaction('wow'));
        $this->assertSame(30, $stats->read_seconds);
    }

    public function test_batch_stats_and_eager_loading(): void
    {
        $a = $this->article();
        $b = $this->article();
        $a->matrix($this->guest())->like();

        $stats = Matrix::statsFor([$a, $b]);
        $this->assertSame(1, $stats[$a->getMorphClass().'|'.$a->id]->likes());
        $this->assertSame(0, $stats[$b->getMorphClass().'|'.$b->id]->likes());

        $loaded = Article::withMatrixStats()->find($a->id);
        $this->assertTrue($loaded->relationLoaded('matrixCounters'));
        $this->assertSame(1, $loaded->matrixStats()->likes());
    }

    public function test_deleting_an_item_for_good_removes_its_engagement(): void
    {
        $article = $this->article();
        $article->matrix($this->guest())->like();

        $article->delete(); // soft: kept
        $this->assertSame(1, $article->reactions()->count());

        $article->forceDelete();
        $this->assertSame(0, Reaction::query()->count());
        $this->assertSame(0, Counter::query()->count());
    }

    public function test_pivoted_counters_for_admin_tables(): void
    {
        $article = $this->article();
        $article->matrix($this->guest())->view();
        $article->matrix($this->guest())->like();

        $row = Counter::query()->pivoted(['views', 'reactions.like'])->first();

        $this->assertSame(1, (int) $row->views);
        $this->assertSame(1, (int) $row->reactions_like);
    }
}

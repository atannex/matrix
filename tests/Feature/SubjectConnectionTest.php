<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Support\Connections;
use Atannex\Matrix\Support\Counters;
use Atannex\Matrix\Tests\Fixtures\Good;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * matrix.follow_subject_connection: an item on its own database keeps its
 * engagement there, so queries over it still read their totals in one
 * statement, while work that spans every item covers every connection.
 */
class SubjectConnectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.shop', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        $app['config']->set('matrix.follow_subject_connection', true);
        $app['config']->set('matrix.connections', ['shop']);
        Connections::flush();
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::connection('shop')->create('goods', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Toghu');
            $table->boolean('published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->artisan('migrate', ['--database' => 'shop', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true]);
    }

    public function test_an_item_on_its_own_database_keeps_its_engagement_there(): void
    {
        [$quiet, $popular] = [Good::query()->create(), Good::query()->create()];
        $article = $this->article();

        $popular->matrix($this->guest())->view();
        $popular->matrix($this->guest())->view();
        $popular->matrix($this->guest())->like();
        $article->matrix($this->guest())->view();

        $this->assertSame(2, DB::connection('shop')->table('matrix_views')->where('engageable_id', $popular->id)->count());
        $this->assertSame(0, DB::table('matrix_views')->where('engageable_type', $popular->getMorphClass())->count());
        $this->assertSame(0, DB::table('matrix_counters')->where('engageable_type', $popular->getMorphClass())->count());
        $this->assertSame(1, DB::table('matrix_views')->where('engageable_type', $article->getMorphClass())->count());

        $this->assertSame([$popular->id, $quiet->id], Good::orderByMatrix('views')->pluck('id')->all());
        $this->assertSame(2, (int) Good::withMatrix('views')->find($popular->id)->matrix_views);
        $this->assertSame(2, $popular->fresh()->matrixStats()->get('views'));
        $this->assertSame(1, $popular->fresh()->matrixCounters()->where('metric', 'reactions.like')->value('value'));
        $this->assertTrue($popular->matrix($this->guest())->supports('reaction'));
    }

    public function test_site_wide_totals_add_every_database(): void
    {
        Good::query()->create()->matrix($this->guest())->view();
        $this->article()->matrix($this->guest())->view();
        $this->article()->matrix($this->guest())->view();

        $counters = app(Counters::class);

        $this->assertSame(['views' => 3], $counters->totals(['views']));
        $this->assertSame(['views' => 1], $counters->totals(['views'], (new Good)->getMorphClass()));
        $this->assertSame(['views' => 3], $counters->totalsBetween(now()->subDay(), now(), ['views']));
    }

    public function test_signing_in_claims_a_guests_engagement_on_every_database(): void
    {
        $guest = $this->guest();
        $good = Good::query()->create();
        $article = $this->article();
        $good->matrix($guest)->like();
        $article->matrix($guest)->like();

        $account = $this->account();
        Matrix::claim($guest->key, $account);

        $this->assertSame($account->key, DB::connection('shop')->table('matrix_reactions')->value('actor_key'));
        $this->assertSame($account->key, DB::table('matrix_reactions')->value('actor_key'));
        $this->assertTrue($good->matrix($account)->liked());
        $this->assertTrue($article->matrix($account)->liked());
    }

    public function test_rebuilding_recounts_items_on_every_database(): void
    {
        $good = Good::query()->create();
        $good->matrix($this->guest())->like();
        DB::connection('shop')->table('matrix_counters')->update(['value' => 99]);

        app(Counters::class)->rebuild();

        $this->assertSame(1, $good->fresh()->matrixStats()->get('reactions.like'));
    }

    public function test_a_signal_can_stay_on_one_database_while_its_totals_follow_the_item(): void
    {
        config(['matrix.signals.comment.follow_subject' => false]);
        $good = Good::query()->create();

        $good->matrix($this->account())->comment('Beautiful ndop work.');

        $this->assertSame(1, DB::table('matrix_comments')->count());
        $this->assertSame(0, DB::connection('shop')->table('matrix_comments')->count());
        $this->assertSame(1, $good->fresh()->matrixStats()->get('comments'));
        $this->assertSame(1, (int) Good::withMatrix('comments')->find($good->id)->matrix_comments);
        $this->assertSame(1, $good->comments()->count());
    }
}

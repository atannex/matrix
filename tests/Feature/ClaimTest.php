<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Author;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\View;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

class ClaimTest extends TestCase
{
    public function test_guest_activity_moves_to_the_account_and_merges(): void
    {
        $article = $this->article();
        $user = $this->user('Ada');
        $account = $this->account($user);
        $guest = $this->guest();

        $article->matrix($account)->view();
        $article->matrix($account)->dislike();
        $article->matrix($guest)->view();
        $article->matrix($guest)->like();
        $article->matrix($guest)->share('x');
        $article->matrix($guest)->comment('Signed with my email', guest: Author::make('Ada', 'ada@example.com'));
        $article->matrix($guest)->comment('Signed with another', guest: Author::make('Ada', 'someone@example.com'));

        $this->assertSame(2, $article->matrixStats()->unique_viewers);

        Matrix::claim($guest->key, $account);

        $stats = $article->matrixStats();
        $this->assertSame(1, $stats->unique_viewers);
        $this->assertSame(2, $stats->views);
        $this->assertSame(1, $stats->reactions); // the account's own reaction wins
        $this->assertSame('dislike', $article->matrix($account)->reaction());
        $this->assertSame(1, $article->shares()->where('actor_key', $account->key)->count());
        $this->assertSame(1, View::query()->count());
        $this->assertSame(1, Comment::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Comment::query()->whereNull('user_id')->count());
    }

    public function test_signing_in_claims_the_browser_cookie(): void
    {
        $article = $this->article();
        $browser = (string) Str::uuid();
        $article->matrix(Actor::guest($browser, '1.2.3.4', 'Mozilla/5.0'))->like();

        $user = $this->user('Bo');
        $this->app['request']->cookies->set(config('matrix.cookie.name'), $browser);

        event(new Login('web', $user, false));

        $this->assertTrue($article->matrix(Actor::forUser($user))->liked());
    }
}

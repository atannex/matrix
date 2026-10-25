<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Contracts\ResolvesClickTargets;
use Atannex\Matrix\Models\Click;
use Atannex\Matrix\Tests\Fixtures\Article;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Support\Str;

class ApiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('matrix.routes.middleware', []);
    }

    public function test_reactions_and_state_round_trip(): void
    {
        $article = $this->article();
        $token = $article->matrixToken();

        $this->postJson("/matrix/{$token}/reactions", ['type' => 'like'])
            ->assertOk()
            ->assertJsonPath('reactions.like', 1)
            ->assertJsonPath('state.reaction', 'like');

        $this->postJson("/matrix/{$token}/ratings", ['value' => 4])->assertOk()->assertJsonPath('stats.average_rating', 4);
        $this->postJson("/matrix/{$token}/ratings", ['value' => 9])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_bad_or_expired_tokens_are_not_found(): void
    {
        $this->getJson('/matrix/abc.def')->assertNotFound();

        $token = $this->article()->matrixToken();
        $this->travel(2)->days();
        $this->getJson("/matrix/{$token}")->assertNotFound();
    }

    public function test_guests_comment_with_a_name_and_never_see_emails(): void
    {
        $token = $this->article()->matrixToken();

        $this->postJson("/matrix/{$token}/comments", ['body' => 'Hello from a guest'])->assertStatus(422)->assertJsonValidationErrors('guest_name');

        $this->postJson("/matrix/{$token}/comments", ['body' => 'Hello from a guest', 'guest_name' => 'Zara', 'guest_email' => 'zara@example.com'])
            ->assertCreated()
            ->assertJsonPath('comment.author', 'Zara')
            ->assertJsonPath('comment.pending', true)
            ->assertJsonMissing(['guest_email' => 'zara@example.com']);
    }

    public function test_closed_items_refuse_with_403(): void
    {
        $token = $this->article(['published' => false])->matrixToken();

        $this->postJson("/matrix/{$token}/reactions", ['type' => 'like'])->assertForbidden();
    }

    public function test_beacons_count_views_and_reading(): void
    {
        $article = $this->article();
        $token = $article->matrixToken();

        $this->withHeader('User-Agent', 'Mozilla/5.0')->post('/matrix/beacon/view', ['token' => $token])->assertNoContent();
        $this->withHeader('User-Agent', 'Mozilla/5.0')->post('/matrix/beacon/read', ['token' => $token, 'seconds' => 12])->assertNoContent();
        $this->post('/matrix/beacon/view', ['token' => 'nope.nope'])->assertNoContent(404);

        $this->assertSame(1, $article->matrixStats()->views);
        $this->assertSame(12, $article->matrixStats()->read_seconds);
    }

    public function test_beacons_answer_bare_422_and_can_stay_silent(): void
    {
        $this->post('/matrix/beacon/read', ['token' => 'x', 'seconds' => 'lots'])->assertNoContent(422);

        config(['matrix.beacon.silent' => true]);
        $this->post('/matrix/beacon/view', ['token' => 'nope.nope'])->assertNoContent();
    }

    public function test_click_beacons_can_send_the_link_instead_of_a_token(): void
    {
        $article = $this->article();
        $this->app->instance(ArticleLinks::class, new ArticleLinks);
        config(['matrix.beacon.click_targets' => ArticleLinks::class]);

        $this->withHeader('User-Agent', 'Mozilla/5.0')->post('/matrix/beacon/click', [
            'url' => "/articles/{$article->id}",
            'placement' => 'home:top',
            'page' => '/?utm_source=mail',
        ])->assertNoContent();
        $this->post('/matrix/beacon/click', ['url' => '/elsewhere'])->assertNoContent(404);

        $this->assertSame(1, $article->matrixStats()->clicks);
        $this->assertSame('/', Click::sole()->page, 'Query strings are dropped.');
    }

    public function test_actions_are_rate_limited(): void
    {
        config(['matrix.limits.reaction' => ['actor' => [2, 60], 'ip' => [100, 60]]]);
        $token = $this->article()->matrixToken();
        $this->withCredentials()->withUnencryptedCookie(config('matrix.cookie.name'), (string) Str::uuid());

        $this->postJson("/matrix/{$token}/reactions", ['type' => 'like'])->assertOk();
        $this->postJson("/matrix/{$token}/reactions", ['type' => 'like'])->assertOk();
        $this->postJson("/matrix/{$token}/reactions", ['type' => 'like'])->assertStatus(422)->assertJsonValidationErrors('type');
    }
}

final class ArticleLinks implements ResolvesClickTargets
{
    public function resolve(string $url): ?Engageable
    {
        return preg_match('#^/articles/(\d+)$#', $url, $m) ? Article::find((int) $m[1]) : null;
    }
}

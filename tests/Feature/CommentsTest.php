<?php

namespace Atannex\Matrix\Tests\Feature;

use Atannex\Matrix\Author;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Events\Commented;
use Atannex\Matrix\Events\Moderated;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class CommentsTest extends TestCase
{
    public function test_verified_accounts_publish_at_once_and_guests_wait(): void
    {
        $article = $this->article();
        $guest = $this->guest();

        $published = $article->matrix($this->account())->comment('Brilliant reporting.');
        $held = $article->matrix($guest)->comment('Who wrote this?', guest: Author::make('Ngozi', 'NGOZI@example.com'));

        $this->assertSame(Status::Approved, $published->status);
        $this->assertSame(Status::Pending, $held->status);
        $this->assertSame('ngozi@example.com', $held->guest_email);
        $this->assertSame(1, $article->matrixStats()->comments);

        // The author sees their own pending comment; others do not.
        $this->assertSame(2, $article->matrix($guest)->commentCount());
        $this->assertSame(1, $article->matrix($this->guest())->commentCount());
    }

    public function test_unverified_accounts_and_anonymous_guests_are_refused(): void
    {
        $article = $this->article();

        try {
            $article->matrix($this->account($this->user('Bob', verified: false)))->comment('Hello there');
            $this->fail('Unverified accounts must verify first.');
        } catch (EngagementRefused $e) {
            $this->assertSame(EngagementRefused::VERIFY_EMAIL, $e->reason);
        }

        $this->expectException(ValidationException::class);
        $article->matrix($this->guest())->comment('No name given');
    }

    public function test_replies_nest_to_the_maximum_depth_then_flatten(): void
    {
        config(['matrix.comments.max_depth' => 2]);
        $article = $this->article();
        $actor = $this->account();

        $top = $article->matrix($actor)->comment('Top');
        $one = $article->matrix($actor)->comment('Reply 1', replyTo: $top->id);
        $two = $article->matrix($actor)->comment('Reply 2', replyTo: $one->id);
        $three = $article->matrix($actor)->comment('Reply 3', replyTo: $two->id);

        $this->assertSame([0, 1, 2, 2], [$top->depth, $one->depth, $two->depth, $three->depth]);
        $this->assertSame($one->id, $three->parent_id);
        $this->assertSame($top->id, $three->root_id);

        $thread = $article->matrix($actor)->comments();
        $this->assertCount(1, $thread);
        $this->assertSame(['Reply 2', 'Reply 3'], $thread[0]->replies[0]->replies->pluck('body')->all());
    }

    public function test_spam_links_and_duplicates(): void
    {
        config(['matrix.comments.blocked_words' => ['casino']]);
        $article = $this->article();
        $actor = $this->account();

        $this->assertSame(Status::Spam, $article->matrix($actor)->comment('Best CASINO bonus')->status);
        $this->assertSame(Status::Pending, $article->matrix($actor)->comment('see http://a.io http://b.io https://c.io')->status);

        $article->matrix($actor)->comment('Same words');
        $this->expectException(ValidationException::class);
        $article->matrix($actor)->comment('Same words');
    }

    public function test_moderation_updates_totals_and_announces_it(): void
    {
        Event::fake([Moderated::class, Commented::class]);
        $article = $this->article();
        $comment = $article->matrix($this->guest())->comment('Pending one', guest: Author::make('Ama', 'ama@example.com'));

        $this->assertSame(0, $article->matrixStats()->comments);

        Matrix::moderate($comment, Status::Approved, $this->account($this->user('Mod', moderator: true)));

        $this->assertSame(1, $article->matrixStats()->comments);
        Event::assertDispatched(Moderated::class, fn (Moderated $e) => $e->context['from'] === Status::Pending && $e->context['to'] === Status::Approved);
        Event::assertDispatched(Commented::class);

        $this->expectException(EngagementRefused::class);
        Matrix::moderate($comment, Status::Spam, $this->account($this->user('Nobody')));
    }

    public function test_authors_edit_inside_the_window_and_delete_their_own(): void
    {
        $article = $this->article();
        $ada = $this->account();
        $comment = $article->matrix($ada)->comment('Frist');

        $edited = $article->matrix($ada)->editComment($comment->id, 'First');
        $this->assertTrue($edited->isEdited());

        $this->travel(16)->minutes();

        try {
            $article->matrix($ada)->editComment($comment->id, 'Too late');
            $this->fail('The edit window has closed.');
        } catch (EngagementRefused) {
        }

        try {
            $article->matrix($this->account($this->user('Eve')))->deleteComment($comment->id);
            $this->fail('Only the author or a moderator may delete.');
        } catch (EngagementRefused) {
        }

        $article->matrix($ada)->deleteComment($comment->id);
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, $article->matrixStats()->comments);
    }

    public function test_comments_collect_likes_with_the_actor_state_in_the_thread(): void
    {
        $article = $this->article();
        $comment = $article->matrix($this->account())->comment('Like me');
        $fan = $this->guest();

        $this->assertTrue($article->matrix($fan)->toggleCommentLike($comment->id));

        $thread = $article->matrix($fan)->comments();
        $this->assertSame(1, $thread[0]->matrixStats()->likes());
        $this->assertSame('like', $thread[0]->matrix_reaction);

        $this->assertFalse($article->matrix($fan)->toggleCommentLike($comment->id));
        $this->assertSame(0, $comment->fresh()->matrixStats()->likes());
    }

    public function test_pending_comments_cannot_be_liked_or_replied_to(): void
    {
        $article = $this->article();
        $guest = $this->guest();
        $held = $article->matrix($guest)->comment('Waiting', guest: Author::make('Kofi', 'kofi@example.com'));

        try {
            $article->matrix($guest)->toggleCommentLike($held->id);
            $this->fail('Pending comments take no reactions.');
        } catch (EngagementRefused) {
        }

        $this->expectException(ValidationException::class);
        $article->matrix($guest)->comment('Reply', replyTo: $held->id, guest: Author::make('Kofi', 'kofi@example.com'));
    }

    public function test_moderators_pin_comments_to_the_top(): void
    {
        $article = $this->article();
        $actor = $this->account();
        $old = $article->matrix($actor)->comment('Old one');
        $this->travel(1)->minutes();
        $article->matrix($actor)->comment('New one');

        Matrix::signal('comment')->pin($old, $this->account($this->user('Mod', moderator: true)));

        $this->assertSame(['Old one', 'New one'], $article->matrix($actor)->comments()->pluck('body')->all());
    }
}

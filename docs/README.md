# atannex/matrix

[![Tests](https://github.com/atannex/matrix/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/matrix/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

The engagement matrix for Laravel. One package for every way people engage with your content: **views, reading time, click-throughs, shares, reactions (like / dislike / love / …), ratings (multi-criteria), reviews, threaded comments, bookmarks, follows and reports**. It works on any Eloquent model, for guests and signed-in accounts alike, and keeps running totals, daily rollups, rankings, trending, moderation and a JSON API in step.

```php
$post->matrix()->like();
$post->matrix()->rate(5);
$post->matrix()->comment('Great read!', replyTo: 12);
$post->matrix()->share('whatsapp');
$post->matrix()->bookmark('read-later');

$post->matrixStats()->views;             // 12 408
$post->matrixStats()->averageRating();   // 4.6
$post->matrixStats()->approval();        // 0.93 (Wilson lower bound)

Post::trending(now()->subWeek())->limit(10)->get();
Post::whereEngagedBy($actor, 'bookmark')->get();   // someone's saved stories
```

## Contents

- [atannex/matrix](#atannexmatrix)
  - [Contents](#contents)
  - [Concepts](#concepts)
  - [Installation](#installation)
  - [Making a model engageable](#making-a-model-engageable)
  - [Engaging](#engaging)
  - [Totals and scores](#totals-and-scores)
  - [Querying and ranking](#querying-and-ranking)
  - [Comments and reviews](#comments-and-reviews)
  - [Moderation and reports](#moderation-and-reports)
  - [Guests](#guests)
  - [HTTP API and the JS client](#http-api-and-the-js-client)
  - [Events](#events)
  - [Custom signals](#custom-signals)
  - [Console](#console)
  - [Configuration reference](#configuration-reference)
  - [Testing](#testing)

## Concepts

| Term             | Meaning                                                                                                                                                                                |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Actor**        | Whoever engages: an account (`user_{id}`) or a guest browser (`guest_{uuid}` from an HTTP-only cookie). Every row is de-duplicated on this key.                                        |
| **Subject**      | One item seen through one actor: `$post->matrix($actor)`. Everything you can do, and what the actor has done.                                                                          |
| **Signal**       | One kind of engagement (`view`, `reaction`, `comment`, …). Each is a class that knows its rows, how to recount its metrics and how to move a guest's rows to an account. Add your own. |
| **Metric**       | A named running total: `views`, `reactions.like`, `ratings_sum:accuracy`, `shares.whatsapp`. Stored as narrow rows, so new metrics need no migration.                                  |
| **Daily rollup** | The same metrics per day, for trending, charts and reports.                                                                                                                            |

Two kinds of signal:

- **Tracking** (view, read, click): never throw. Bots, closed items and reloads inside the view window are silently ignored.
- **Interactive** (reaction, rating, review, comment, share, bookmark, follow, report): refuse loudly with `EngagementRefused` (403) or `ValidationException` (422).

Totals stay correct by construction: high-volume signals add atomically; toggles recount the item's signal from the raw rows, so they never drift. `matrix:recount` corrects anything else.

## Installation

```bash
composer require atannex/matrix
php artisan migrate
php artisan vendor:publish --tag=matrix-config   # optional
```

The service provider and `Matrix` facade are auto-discovered. Tables are prefixed `matrix_` (configurable).

## Making a model engageable

```php
use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;

class Post extends Model implements Engageable
{
    use InteractsWithMatrix;

    // Optional: close engagement (all signals, or some).
    public function acceptsEngagement(?string $signal = null): bool
    {
        return $this->published_at?->isPast() ?? false;
    }

    // Optional: only these signals (null = all).
    public function matrixSignals(): ?array
    {
        return ['view', 'read', 'click', 'share', 'reaction', 'rating', 'comment', 'bookmark', 'report'];
    }
}
```

The trait adds relations (`views`, `reactions`, `ratings`, `reviews`, `comments`, `shares`, `clicks`, `bookmarks`, `follows`, `reports`, `matrixCounters`, `matrixDaily`), query scopes, and removes the item's engagement when it is deleted for good (soft deletes keep it).

Comments and reviews are engageable themselves: comments take reactions (likes) and reports, reviews take reactions ("helpful") and reports.

## Engaging

`$post->matrix()` acts as the current request's actor. Pass an `Actor` to act as someone else (jobs, imports, tests):

```php
use Atannex\Matrix\Actor;

$post->matrix(Actor::forUser($user))->like();
$post->matrix()->as(Actor::guest($browserId))->view();
```

| Signal   | Methods                                                                                                                                                                                        |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| View     | `view(): ViewOutcome` (FirstView, FirstToday, Repeat, Ignored)                                                                                                                                 |
| Reading  | `read(int $seconds, bool $first = true)`                                                                                                                                                       |
| Click    | `click(['placement' => 'hero', 'page' => '/'])`                                                                                                                                                |
| Share    | `share('whatsapp')`                                                                                                                                                                            |
| Reaction | `react('love')`, `toggleReaction('love')`, `unreact()`, `reaction()`, `like()`, `unlike()`, `toggleLike()`, `liked()`, `dislike()`, `undislike()`, `toggleDislike()`, `disliked()`             |
| Rating   | `rate(4)`, `rate(5, 'accuracy')`, `unrate()`, `rating()`, `ratings()`                                                                                                                          |
| Review   | `review($body, rating: 4, title: 'Solid', guest: $author)`, `myReview()`, `unreview()`, `reviews(perPage, sort)`                                                                               |
| Comment  | `comment($body, replyTo: $id, guest: $author)`, `comments(limit, offset)`, `commentCount()`, `editComment($id, $body)`, `deleteComment($id)`, `toggleCommentLike($id)`, `commentRestriction()` |
| Bookmark | `bookmark('weekend', note: '…')`, `unbookmark()`, `toggleBookmark()`, `bookmarked()`                                                                                                           |
| Follow   | `follow(notify: true)`, `unfollow()`, `toggleFollow()`, `following()`                                                                                                                          |
| Report   | `report('abuse', 'details')`, `reported()`                                                                                                                                                     |
| State    | `state()`: the actor's reaction, ratings, review, bookmarked, following, reported, for rendering buttons                                                                                       |

Rate limits from `config('matrix.limits')` apply when you ask for them, per actor **and** per IP:

```php
$post->matrix()->throttled('rating')->rate(5); // ValidationException on "rating" when over the limit
```

## Totals and scores

```php
$stats = $post->matrixStats();      // or Matrix::stats($post)

$stats->views;                      // any metric as a property
$stats->get('reactions.love');
$stats->reactionBreakdown();        // ['like' => 120, 'love' => 31, …]
$stats->shareBreakdown();           // ['whatsapp' => 80, 'x' => 12]
$stats->averageRating();            // 4.33
$stats->averageRating('accuracy');
$stats->bayesianRating();           // pulled toward the prior: fair with few votes
$stats->ratingHistogram();          // [5 => 60, 4 => 25, …] percent
$stats->averageReviewRating();
$stats->averageReadSeconds();
$stats->approval();                 // Wilson lower bound of positive vs negative reactions
$stats->score();                    // weighted sum (config "score.weights")
$stats->hotness();                  // score decayed by age (gravity)
```

Built-in metrics:

| Metric                                                        | From                                       |
| ------------------------------------------------------------- | ------------------------------------------ |
| `views`, `unique_viewers`                                     | views                                      |
| `reads`, `read_seconds`                                       | reading beacons (counter-only)             |
| `clicks`, `clicks.{placement}`                                | clicks (counter-only: raw rows are pruned) |
| `shares`, `shares.{platform}`                                 | shares                                     |
| `reactions`, `reactions.{type}`                               | reactions                                  |
| `ratings`, `ratings_sum`, `ratings.{score}`                   | overall ratings (and histogram)            |
| `ratings:{dim}`, `ratings_sum:{dim}`, `ratings:{dim}.{score}` | ratings on another dimension               |
| `reviews`, `reviews_rated`, `reviews_rating_sum`              | approved reviews                           |
| `comments`                                                    | approved comments                          |
| `bookmarks`                                                   | distinct actors who saved it               |
| `followers`                                                   | follows                                    |
| `reports`                                                     | open reports                               |

Many items in one query:

```php
$stats = Matrix::statsFor($posts);           // ["{morph}|{id}" => Stats]
Post::withMatrixStats()->get();              // eager-loads, then ->matrixStats() is free
```

Site-wide figures without a query of your own:

```php
Matrix::counters()->totals(['views', 'comments'], Post::class);              // all time, per metric
Matrix::counters()->totals(['views'], Post::class, $posts->select('id'));    // for some items (ids or a subquery)
Matrix::counters()->totalsBetween($from, $to, ['views']);                   // from the daily rollups
Matrix::counters()->breakdownBetween($from, $to, 'shares');                 // ['whatsapp' => 12, 'link' => 4]
Matrix::counters()->table('view');                                          // a configured table name
```

## Querying and ranking

```php
Post::withMatrix('views', 'reactions.like')->get();       // ->matrix_views, ->matrix_reactions_like
Post::orderByMatrix('views')->get();
Post::whereMatrix('comments', '>=', 10)->get();
Post::withMatrixSince('views', now()->subWeek())->get();  // ->matrix_views_since
Post::orderByMatrixSince('views', now()->startOfMonth())->get();
Post::whereMatrixSince('views', now()->subDay())->get();  // only items read since
Post::trending(now()->subDays(7))->get();                 // weighted daily score, ->matrix_trending
Post::trending(now()->subDay(), ['shares' => 5, 'comments' => 3])->get();
Post::whereEngagedBy($actor, 'bookmark', ['collection' => 'weekend'])->get();
```

For your own selects, the correlated subqueries are public:

```php
Post::query()->addSelect(['views' => Post::matrixMetric('views')]);
Post::query()->orderBy(Post::matrixMetricSince('shares', now()->subWeek()), 'desc');
Post::query()->addSelect(['score' => Post::matrixScoreSince(now()->subWeek())]);
Post::query()->orderByDesc(Post::matrixScore());                     // all-time weighted score
Post::query()->addSelect(['stars' => Post::matrixAverageRating()]);  // 0 when unrated
```

Site-wide figures (dashboards):

```php
Matrix::counters()->totalsBetween($from, $to, ['views', 'comments']);
Matrix::counters()->dailyBetween($from, $to, ['views'], type: (new Post)->getMorphClass());
Matrix::counters()->top((new Post)->getMorphClass(), 'views', now()->subWeek(), 10);
Counter::query()->pivoted(['views', 'reactions.like'])->get();  // one row per item: admin tables
```

## Comments and reviews

- Verified accounts publish at once; unverified accounts must verify their email first (`verified_only`, using `hasVerifiedEmail()`); guests sign with an `Author` and are held for moderation (`moderate_guests`).
- Replies nest to `comments.max_depth`; deeper replies hang off the deepest allowed comment.
- More than `max_links` links → held. A `blocked_words` match → spam. The same text by the same actor within `duplicate_window` seconds → refused.
- Authors may edit or delete within `edit_window` minutes; moderators always.
- Moderators pin comments: `Matrix::signal('comment')->pin($comment, $moderator)`.
- One review per actor per item; writing again inside the edit window updates it.

```php
use Atannex\Matrix\Author;

$post->matrix()->comment($body, guest: Author::make($request->name, $request->email));

$thread = $post->matrix()->comments(limit: 20);   // pinned first, newest next
foreach ($thread as $comment) {
    $comment->authorName();
    $comment->matrixStats()->likes();
    $comment->matrix_reaction;                    // the actor's reaction to it
    $comment->replies;                            // nested, already loaded
}
```

Threads load `user` for every comment; load more with `Matrix::signal('comment')->loadWith(['user.media'])`.

## Moderation and reports

Moderators are decided by the `matrix.moderate` gate ability, or by a callback:

```php
Matrix::moderatorsUsing(fn ($user) => $user->hasRole('editor'));
// The comment or review being moderated is passed too, when there is one:
Matrix::moderatorsUsing(fn ($user, ?Model $content) => $content instanceof Review ? $user->can('reviews') : $user->can('comments'));

Matrix::moderate($comment, Status::Approved, $moderatorActor);
```

Status changes made anywhere (an admin panel saving the model included) update the totals and fire `Moderated`.

Moderation states come from `Models\Concerns\IsModerated` (atannex/scopable), used by comments and reviews and available to any model with a `status` column moderated the same way (an app's testimonials, say):

```php
Comment::pending()->count();
Comment::countStates(['pending', 'approved', 'spam']);   // one query
Review::whereAnyState(['rejected', 'spam']);
$comment->inState('approved');                           // isApproved(), isPending() too
$comment->leaveState('approved');                        // back to pending
Report::open(); Report::resolved(); Report::dismissed();
```

Every matrix record also has date scopes: `Click::createdToday()`, `Reaction::createdWithin('7 days')`, `DailyCounter::dayIn('last 7 calendar days')`.

Reports collect against any item. When a comment or review (anything implementing `Moderatable`) reaches `reports.hide_threshold` open reports, it is hidden until a moderator looks at it. Close reports with `Matrix::signal('report')->resolve($report, $moderator, ReportStatus::Resolved, 'Removed')`.

## Guests

Guests are recognised by an HTTP-only cookie (`matrix.cookie.name`). On sign-in (`Illuminate\Auth\Events\Login`) everything the browser did as a guest moves to the account: where both have a row for the same item the account's wins (view and share counts are added together), and affected items are recounted. Comments and reviews carry a public name, so only those signed with the account's verified email are claimed.

Set `signals.{name}.guests` to `false` to require an account for a signal (follows do by default).

## HTTP API and the JS client

Routes live under `/matrix` (configurable, or disable with `matrix.routes.enabled`). Items are addressed by a **signed, expiring token** (`$post->matrixToken()`), so nobody can post engagement for items your site never showed them.

| Method              | Path                                 |                                                        |
| ------------------- | ------------------------------------ | ------------------------------------------------------ |
| POST                | `/matrix/beacon/view`                | `token`                                                |
| POST                | `/matrix/beacon/read`                | `token`, `seconds`, `first`                            |
| POST                | `/matrix/beacon/click`               | `token` (or `url`), `placement`, `page`                |
| GET                 | `/matrix/{token}`                    | totals + actor state                                   |
| POST / DELETE       | `/matrix/{token}/reactions`          | `type`, `toggle`                                       |
| POST / DELETE       | `/matrix/{token}/ratings`            | `value`, `dimension`, `comment`                        |
| GET / POST / DELETE | `/matrix/{token}/reviews`            | `body`, `rating`, `title`, `guest_name`, `guest_email` |
| GET / POST          | `/matrix/{token}/comments`           | `body`, `reply_to`, `guest_name`, `guest_email`        |
| PATCH / DELETE      | `/matrix/{token}/comments/{id}`      | `body`                                                 |
| POST                | `/matrix/{token}/comments/{id}/like` |                                                        |
| POST                | `/matrix/{token}/shares`             | `platform`                                             |
| POST / DELETE       | `/matrix/{token}/bookmark`           | `collection`, `note`                                   |
| POST / DELETE       | `/matrix/{token}/follow`             | `notify`                                               |
| POST                | `/matrix/{token}/reports`            | `reason`, `details`                                    |

Beacons answer with an empty body, and a bare `422` for invalid input. Unknown tokens get `404`, or `204` with `matrix.beacon.silent`. To send click beacons with the link's address instead of a token, bind a `Contracts\ResolvesClickTargets` class in `matrix.beacon.click_targets`; `page` is stored without its query string.

Writes answer with `{ stats, reactions, state }`, so a client can re-render from any response. Emails, IPs and actor keys are never exposed.

Publish the client with `php artisan vendor:publish --tag=matrix-js`:

```js
import { Matrix } from './vendor/matrix.js';

const post = new Matrix(token);
post.trackView(articleElement);   // counted once half the article is on screen for a second
post.trackReading();              // active, visible time only
await post.react('like');
```

Or declaratively:

```html
<article data-matrix-token="{{ $post->matrixToken() }}" data-matrix-track="view read">
  <button data-matrix-action="like">👍 <span data-matrix-stat="reactions.like"></span></button>
</article>
<script type="module">import { Matrix } from '/js/vendor/matrix.js'; Matrix.autoTrack();</script>
```

## Events

Every event extends `Atannex\Matrix\Events\Engagement` and implements `MatrixEvent`, so one listener can see all engagement:

```php
Event::listen(MatrixEvent::class, fn (MatrixEvent $e) => Log::info($e->signal(), ['positive' => $e->positive()]));
```

`Viewed`, `Read`, `Clicked`, `Shared`, `Reacted`, `Unreacted`, `Rated`, `Unrated`, `Reviewed`, `ReviewRemoved`, `Commented`, `CommentEdited`, `CommentRemoved`, `Bookmarked`, `Unbookmarked`, `Followed`, `Unfollowed`, `Reported`, `ReportResolved`, `Moderated`. Each carries `$subject` (the item), `$actor`, `$record` (the row) and `$context`.

## Custom signals

```php
use Atannex\Matrix\Signals\Signal;

final class PledgeSignal extends Signal
{
    public function name(): string { return 'pledge'; }

    public function owns(string $metric): bool { return $metric === 'pledged_cents'; }

    public function recount(string $type, int|string $id): array
    {
        return ['pledged_cents' => (int) $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->sum('cents')];
    }
}

Matrix::extend(new PledgeSignal);   // add config('matrix.models.pledge'), ('matrix.tables.pledge')
```

## Console

| Command                    |                                                                       |
| -------------------------- | --------------------------------------------------------------------- |
| `matrix:recount {--type=}` | Recompute every item's totals from the raw rows. Schedule it nightly. |
| `matrix:stats {type} {id}` | An item's totals and scores.                                          |
| `matrix:signals`           | Registered signals.                                                   |
| `matrix:prune`             | Delete raw click rows past `signals.click.retention` days.            |

## Configuration reference

See `config/matrix.php`: actor cookie, privacy (`anonymize_ip`, `count_bots`, `bot_detection`: with [atannex/detector](https://github.com/atannex/detector) installed its bot list decides what is never counted, otherwise a name pattern), per-signal settings (`guests`, view `window`, read `max_seconds`, click `debounce`/`retention`, share `platforms`), reaction types with emoji and sentiment, rating scale, dimensions and Bayesian prior, comment and review rules, report reasons and threshold, score weights and gravity, rate limits, token lifetime, routes, models and tables.

Swap any model for your own subclass (`matrix.models.*`) to add relations, casts or activity logging.

## Testing

```bash
cd packages/atannex/matrix
composer install
vendor/bin/phpunit
```

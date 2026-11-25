# Matrix

[![Tests](https://github.com/atannex/matrix/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/matrix/actions/workflows/tests.yml)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel 11 | 12 | 13](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

A simple way to add engagement tracking to Laravel models. Track views, reads, clicks, shares, reactions, ratings, reviews, comments, bookmarks, follows and reports.

Works with Eloquent models, signed-in users and guests.

## Installation

```bash
composer require atannex/matrix
php artisan migrate
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=matrix-config
```

## Setup a model

Add the `InteractsWithMatrix` trait and implement `Engageable` on the model you want to track.

```php
use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements Engageable
{
    use InteractsWithMatrix;
}
```

## Use it

You can record engagement directly from your model:

```php
$post->matrix()->view();
$post->matrix()->like();
$post->matrix()->react('love');
$post->matrix()->rate(5);
$post->matrix()->comment('Great post!');
$post->matrix()->share('whatsapp');
$post->matrix()->bookmark();
```

You can also check the current actor's engagement:

```php
$post->matrix()->liked();
$post->matrix()->bookmarked();
$post->matrix()->following();
$post->matrix()->state();
```

## Get totals

```php
$stats = $post->matrixStats();

$stats->views;
$stats->get('reactions.like');
$stats->averageRating();
$stats->reactionBreakdown();
$stats->shareBreakdown();
$stats->approval();
```

## Query engaged content

```php
Post::orderByMatrix('views')->get();
Post::whereMatrix('comments', '>=', 10)->get();
Post::trending(now()->subDays(7))->get();
Post::whereEngagedBy($actor, 'bookmark')->get();
```

## Comments

Comments can have replies, reactions and reports. You can retrieve and manage them through the matrix API:

```php
$comments = $post->matrix()->comments(limit: 20);
$post->matrix()->comment('A comment', replyTo: $parentCommentId);
$post->matrix()->editComment($commentId, 'Updated comment');
$post->matrix()->deleteComment($commentId);
```

Deleting a comment also deletes its replies at every depth, along with the reactions, reports and totals recorded on them.

Reviews are also supported:

```php
$post->matrix()->review('My review', rating: 4, title: 'Good');
$post->matrix()->myReview();
```

## Reports

```php
$comment->matrix()->report('spam', 'Posted the same link five times');
$post->matrix()->reported();
```

Content that collects `reports.hide_threshold` open reports is hidden until a moderator looks at it. Once a moderator resolves or dismisses a report, its author cannot reopen it by reporting the same item again.

## Deleting

With `cleanup_on_delete` on (the default):

- **Deleting a model for good** (not a soft delete) removes its engagement and totals, including the reactions, reports and totals recorded on its comments and reviews.
- **Deleting a user for good** removes their reactions, bookmarks, follows, reports, comments and reviews, then recounts every item they touched. Their views, ratings, shares and clicks stay in the totals but are no longer linked to them, so a new account that reuses the id does not inherit them.

You can also run this cleanup yourself:

```php
Matrix::forget($post);
Matrix::forgetUser($user);
```

If totals ever drift, rebuild them from the raw rows:

```bash
php artisan matrix:recount
```

## Blade

Add the package's JavaScript client to track engagement from the browser. Publish it first:

```bash
php artisan vendor:publish --tag=matrix-js
```

Then use a tracking token on your content:

```blade
<article data-matrix-token="{{ $post->matrixToken() }}" data-matrix-track="view read">
    <button data-matrix-action="like">
        Like <span data-matrix-stat="reactions.like"></span>
    </button>
</article>

<script type="module">
    import { Matrix } from '/js/vendor/matrix.js';
    Matrix.autoTrack();
</script>
```

Tokens are signed and encrypted: they prove the page was rendered by your site and do not reveal the model or its id. They expire after `tokens.lifetime` minutes (a day by default).

`Matrix.autoTrack()` is safe to call again after you render more items; elements it already set up are skipped. `data-matrix-action` accepts the engagement actions only: `like`, `dislike`, `react`, `unreact`, `rate`, `unrate`, `unreview`, `share`, `bookmark`, `unbookmark`, `follow`, `unfollow`, `report`, `likeComment` and `deleteComment`.

To track by hand, use the client directly. `trackReading()` returns a function that sends the remaining seconds and stops tracking, e.g. when a single-page app navigates away:

```js
const post = new Matrix(token);
post.trackView(element);
const stopReading = post.trackReading();

// later
stopReading();
```

## Configuration

The configuration file is `config/matrix.php`. It lets you adjust guest access, tracking behavior, reactions, ratings, comments, moderation, rate limits and routes.

## License

MIT

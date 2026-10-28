<?php

use Atannex\Matrix\Models\Bookmark;
use Atannex\Matrix\Models\Click;
use Atannex\Matrix\Models\Comment;
use Atannex\Matrix\Models\Counter;
use Atannex\Matrix\Models\DailyCounter;
use Atannex\Matrix\Models\Follow;
use Atannex\Matrix\Models\Rating;
use Atannex\Matrix\Models\Reaction;
use Atannex\Matrix\Models\Report;
use Atannex\Matrix\Models\Review;
use Atannex\Matrix\Models\Share;
use Atannex\Matrix\Models\View;

return [

    /*
    |--------------------------------------------------------------------------
    | Actors
    |--------------------------------------------------------------------------
    |
    | Everyone who engages is an actor: a signed-in account ("user_{id}") or a
    | guest browser ("guest_{uuid}" from an HTTP-only cookie). Every engagement
    | row is de-duplicated on that key, and when a guest signs in everything
    | recorded under the cookie moves to the account (claim_on_login).
    |
    */

    'user_model' => env('MATRIX_USER_MODEL', 'App\\Models\\User'),

    'guard' => null,

    'cookie' => [
        'name' => env('MATRIX_COOKIE', 'matrix_actor'),
        'lifetime' => 60 * 24 * 365 * 2, // minutes (two years)
    ],

    'claim_on_login' => true,

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | anonymize_ip: analytics rows (views, reactions, ratings, shares, clicks,
    | bookmarks, follows) keep the IP with its host part zeroed. Comments,
    | reviews and reports keep the full address for moderation.
    |
    */

    'privacy' => [
        'anonymize_ip' => (bool) env('MATRIX_ANONYMIZE_IP', true),
        'count_bots' => false,
        // auto: atannex/detector decides what is a bot when it is installed,
        // a name pattern otherwise. pattern: always the name pattern.
        'bot_detection' => 'auto',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signals
    |--------------------------------------------------------------------------
    |
    | Every kind of engagement is a signal. "guests" says whether guests may
    | send it; models narrow the list further with matrixSignals().
    |
    */

    'signals' => [
        'view' => ['guests' => true, 'window' => 30],          // minutes a reload is not a new view
        'read' => ['guests' => true, 'max_seconds' => 1800],   // cap per beacon
        'click' => ['guests' => true, 'debounce' => 10, 'retention' => 90], // seconds; days raw rows are kept
        'share' => ['guests' => true, 'platforms' => null],     // null = any platform name
        'reaction' => ['guests' => true],
        'rating' => ['guests' => true],
        'review' => ['guests' => true],
        'comment' => ['guests' => true],
        'bookmark' => ['guests' => true],
        'follow' => ['guests' => false],
        'report' => ['guests' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    |
    | One reaction per actor per item; reacting again with the same type takes
    | it back, another type switches. Counted as "reactions.{type}" plus the
    | "reactions" total. "sentiment" feeds the Wilson score (+1 / -1 / 0).
    |
    */

    'reactions' => [
        'like' => ['emoji' => '👍', 'sentiment' => 1],
        'dislike' => ['emoji' => '👎', 'sentiment' => -1],
        'love' => ['emoji' => '❤️', 'sentiment' => 1],
        'laugh' => ['emoji' => '😂', 'sentiment' => 1],
        'wow' => ['emoji' => '😮', 'sentiment' => 0],
        'sad' => ['emoji' => '😢', 'sentiment' => 0],
        'angry' => ['emoji' => '😠', 'sentiment' => -1],
        'helpful' => ['emoji' => '🙌', 'sentiment' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ratings
    |--------------------------------------------------------------------------
    |
    | dimensions: criteria an item can be rated on ("overall" is the default).
    | null allows any dimension name. The Bayesian prior pulls items with few
    | votes toward "mean" as if they had "weight" extra votes.
    |
    */

    'ratings' => [
        'min' => 1,
        'max' => 5,
        'dimensions' => null,
        'toggle' => true, // giving the same score again takes the rating back
        'bayesian' => ['mean' => 3.0, 'weight' => 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments & Reviews
    |--------------------------------------------------------------------------
    |
    | moderate_guests:  guest posts wait for a moderator (the author still
    |                   sees them).
    | verified_only:    accounts must have verified their email address.
    | max_depth:        replies deeper than this hang off the deepest allowed
    |                   comment instead.
    | edit_window:      minutes an author may edit their own post.
    | max_links:        posts with more links are held for moderation.
    | blocked_words:    posts containing any of these are marked spam.
    | duplicate_window: seconds in which the same text by the same actor on
    |                   the same item is refused.
    |
    */

    'comments' => [
        'moderate_guests' => true,
        'moderate_all' => false,
        'verified_only' => true,
        'min_length' => 2,
        'max_length' => 5000,
        'max_depth' => 3,
        'edit_window' => 15,
        'max_links' => 2,
        'blocked_words' => [],
        'duplicate_window' => 600,
    ],

    'reviews' => [
        'moderate_guests' => true,
        'moderate_all' => false,
        'verified_only' => true,
        'min_length' => 10,
        'max_length' => 10000,
        'edit_window' => 15,
        'max_links' => 2,
        'blocked_words' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | When an item that can be moderated (comments, reviews) collects this many
    | open reports it is hidden until a moderator looks at it.
    |
    */

    'reports' => [
        'reasons' => ['spam', 'abuse', 'harassment', 'misinformation', 'off_topic', 'other'],
        'hide_threshold' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | weights:  how much one unit of each metric counts toward an item's
    |           engagement score (and the trending order).
    | gravity:  how quickly the "hot" score decays with age (Hacker News: 1.8).
    |
    */

    'score' => [
        'weights' => [
            'views' => 1,
            'clicks' => 1,
            'reads' => 2,
            'reactions' => 2,
            'comments' => 3,
            'shares' => 3,
            'ratings' => 2,
            'reviews' => 4,
            'bookmarks' => 3,
            'followers' => 2,
        ],
        'gravity' => 1.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | [max attempts, per seconds], per actor and per IP address (so clearing
    | cookies does not get a guest around the actor limit).
    |
    */

    'limits' => [
        'reaction' => ['actor' => [10, 10], 'ip' => [120, 60]],
        'rating' => ['actor' => [5, 10], 'ip' => [60, 60]],
        'review' => ['actor' => [3, 600], 'ip' => [10, 600]],
        'comment' => ['actor' => [5, 60], 'ip' => [20, 600]],
        'share' => ['actor' => [20, 60], 'ip' => [120, 60]],
        'bookmark' => ['actor' => [30, 60], 'ip' => [120, 60]],
        'follow' => ['actor' => [30, 60], 'ip' => [120, 60]],
        'report' => ['actor' => [5, 600], 'ip' => [20, 600]],
        'beacon' => ['actor' => [120, 60], 'ip' => [240, 60]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking tokens
    |--------------------------------------------------------------------------
    |
    | Pages embed a signed token per item; beacons and API calls send it back,
    | so engagement cannot be posted for items the site never showed. Minutes.
    |
    */

    /*
    | Tracking beacons (/matrix/beacon/*). "silent" answers 204 for unknown
    | tokens too, so a beacon reveals nothing about what was counted.
    | "click_targets" (a ResolvesClickTargets class) lets click beacons send
    | the link's url instead of a token.
    */
    'beacon' => [
        'silent' => false,
        'click_targets' => null,
    ],

    'tokens' => [
        'lifetime' => 60 * 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'matrix',
        'name' => 'matrix.',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    |
    | cleanup_on_delete: when an engageable model is deleted for good (not
    | soft-deleted), its engagement rows and counters go with it.
    |
    */

    'cleanup_on_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Models & tables
    |--------------------------------------------------------------------------
    |
    | Swap a model for your own subclass to add relations, casts or activity
    | logging; the engine always resolves models from here.
    |
    */

    'connection' => env('MATRIX_DB_CONNECTION'),

    // Key type of engageable models: integer, uuid or ulid (read by the migration).
    'morph_key' => 'integer',

    'models' => [
        'view' => View::class,
        'click' => Click::class,
        'share' => Share::class,
        'reaction' => Reaction::class,
        'rating' => Rating::class,
        'review' => Review::class,
        'comment' => Comment::class,
        'bookmark' => Bookmark::class,
        'follow' => Follow::class,
        'report' => Report::class,
        'counter' => Counter::class,
        'daily' => DailyCounter::class,
    ],

    'tables' => [
        'view' => 'matrix_views',
        'click' => 'matrix_clicks',
        'share' => 'matrix_shares',
        'reaction' => 'matrix_reactions',
        'rating' => 'matrix_ratings',
        'review' => 'matrix_reviews',
        'comment' => 'matrix_comments',
        'bookmark' => 'matrix_bookmarks',
        'follow' => 'matrix_follows',
        'report' => 'matrix_reports',
        'counter' => 'matrix_counters',
        'daily' => 'matrix_daily',
    ],

];

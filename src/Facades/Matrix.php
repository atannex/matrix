<?php

declare(strict_types=1);

namespace Atannex\Matrix\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Atannex\Matrix\Subject for(\Illuminate\Database\Eloquent\Model $item, ?\Atannex\Matrix\Actor $actor = null)
 * @method static \Atannex\Matrix\Actor actor()
 * @method static \Atannex\Matrix\Actor actorFor(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Atannex\Matrix\Stats stats(\Illuminate\Database\Eloquent\Model $item)
 * @method static array<string, \Atannex\Matrix\Stats> statsFor(iterable $items)
 * @method static string token(\Illuminate\Database\Eloquent\Model $item)
 * @method static \Atannex\Matrix\Signals\Signal signal(string $name)
 * @method static array<string, \Atannex\Matrix\Signals\Signal> signals()
 * @method static void extend(\Atannex\Matrix\Signals\Signal $signal)
 * @method static bool supports(\Illuminate\Database\Eloquent\Model $item, string $signal)
 * @method static bool guard(\Illuminate\Database\Eloquent\Model $item, string $signal, \Atannex\Matrix\Actor $actor, bool $ignoreClosed = false)
 * @method static bool isModerator(?\Atannex\Matrix\Actor $actor, ?\Illuminate\Database\Eloquent\Model $content = null)
 * @method static void moderatorsUsing(?\Closure $callback)
 * @method static \Illuminate\Database\Eloquent\Model moderate(\Illuminate\Database\Eloquent\Model $content, \Atannex\Matrix\Enums\Status $status, \Atannex\Matrix\Actor $moderator)
 * @method static void commentsChanged(\Atannex\Matrix\Models\Comment $comment)
 * @method static void reviewsChanged(\Atannex\Matrix\Models\Review $review)
 * @method static int claim(string $guestKey, \Atannex\Matrix\Actor $account)
 * @method static void forget(\Illuminate\Database\Eloquent\Model $item)
 * @method static \Atannex\Matrix\Support\Counters counters()
 * @method static \Atannex\Matrix\Support\Throttle throttle()
 * @method static \Atannex\Matrix\Support\Tokens tokens()
 * @method static \Atannex\Matrix\Support\Moderation moderation()
 *
 * @see \Atannex\Matrix\Matrix
 */
class Matrix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Matrix\Matrix::class;
    }
}

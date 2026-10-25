<?php

declare(strict_types=1);

namespace Atannex\Matrix\Enums;

/**
 * What a page view amounted to.
 */
enum ViewOutcome
{
    /** First time this actor opened the item: a view and a new unique viewer. */
    case FirstView;

    /** Returning actor, first visit today: a view and a new daily viewer. */
    case FirstToday;

    /** Returning actor, already counted today: a view. */
    case Repeat;

    /** Reload inside the de-duplication window, a bot, or engagement closed. */
    case Ignored;

    public function counts(): bool
    {
        return $this !== self::Ignored;
    }
}

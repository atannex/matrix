<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * An item saved. context: collection.
 */
final class Bookmarked extends Engagement
{
    public function signal(): string
    {
        return 'bookmark';
    }
}

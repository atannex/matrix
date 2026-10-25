<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * An item unfollowed.
 */
final class Unfollowed extends Engagement
{
    public function signal(): string
    {
        return 'follow';
    }

    public function positive(): bool
    {
        return false;
    }
}

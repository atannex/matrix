<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A bookmark removed. context: collection.
 */
final class Unbookmarked extends Engagement
{
    public function signal(): string
    {
        return 'bookmark';
    }

    public function positive(): bool
    {
        return false;
    }
}

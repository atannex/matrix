<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A review deleted by its author or a moderator.
 */
final class ReviewRemoved extends Engagement
{
    public function signal(): string
    {
        return 'review';
    }

    public function positive(): bool
    {
        return false;
    }
}

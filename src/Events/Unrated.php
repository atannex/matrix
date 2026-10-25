<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A rating taken back. context: value, dimension.
 */
final class Unrated extends Engagement
{
    public function signal(): string
    {
        return 'rating';
    }

    public function positive(): bool
    {
        return false;
    }
}

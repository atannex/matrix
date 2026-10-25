<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A rating given or changed. context: value, previous, dimension.
 */
final class Rated extends Engagement
{
    public function signal(): string
    {
        return 'rating';
    }
}

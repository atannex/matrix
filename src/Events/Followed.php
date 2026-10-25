<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * An item followed.
 */
final class Followed extends Engagement
{
    public function signal(): string
    {
        return 'follow';
    }
}

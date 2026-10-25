<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A share. context: platform.
 */
final class Shared extends Engagement
{
    public function signal(): string
    {
        return 'share';
    }
}

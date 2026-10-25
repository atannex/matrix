<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * An item reported. context: reason, hidden (bool: it crossed the threshold).
 */
final class Reported extends Engagement
{
    public function signal(): string
    {
        return 'report';
    }
}

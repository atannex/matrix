<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A counted page view. context: outcome (ViewOutcome).
 */
final class Viewed extends Engagement
{
    public function signal(): string
    {
        return 'view';
    }
}

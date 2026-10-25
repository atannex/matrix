<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * Engaged reading time reported. context: seconds, first.
 */
final class Read extends Engagement
{
    public function signal(): string
    {
        return 'read';
    }
}

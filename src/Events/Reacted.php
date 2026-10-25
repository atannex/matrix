<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A reaction added or switched. context: type, previous (type or null).
 */
final class Reacted extends Engagement
{
    public function signal(): string
    {
        return 'reaction';
    }
}

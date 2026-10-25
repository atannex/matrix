<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A reaction taken back. context: type.
 */
final class Unreacted extends Engagement
{
    public function signal(): string
    {
        return 'reaction';
    }

    public function positive(): bool
    {
        return false;
    }
}

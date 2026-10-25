<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A comment or reply posted. context: reply (bool).
 */
final class Commented extends Engagement
{
    public function signal(): string
    {
        return 'comment';
    }
}

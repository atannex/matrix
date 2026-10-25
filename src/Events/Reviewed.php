<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A review written or edited. context: updated (bool).
 */
final class Reviewed extends Engagement
{
    public function signal(): string
    {
        return 'review';
    }
}

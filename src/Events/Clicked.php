<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A click-through from a listing. context: placement, page, referrer_host.
 */
final class Clicked extends Engagement
{
    public function signal(): string
    {
        return 'click';
    }
}

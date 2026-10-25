<?php

declare(strict_types=1);

namespace Atannex\Matrix\Contracts;

use Atannex\Matrix\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * Every engagement event implements this: listen for MatrixEvent::class to
 * see them all (activity feeds, notifications, webhooks, analytics).
 *
 * @property-read Model $subject
 * @property-read Actor $actor
 */
interface MatrixEvent
{
    /**
     * The signal that fired ("reaction", "comment", ...).
     */
    public function signal(): string;

    /**
     * Whether the actor added engagement (true) or took it back (false).
     */
    public function positive(): bool;
}

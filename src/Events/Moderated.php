<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A comment or review changed moderation status. $subject is the comment or review. context: from, to (Status).
 */
final class Moderated extends Engagement
{
    public function signal(): string
    {
        return 'moderation';
    }
}

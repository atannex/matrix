<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A comment deleted by its author or a moderator.
 */
final class CommentRemoved extends Engagement
{
    public function signal(): string
    {
        return 'comment';
    }

    public function positive(): bool
    {
        return false;
    }
}

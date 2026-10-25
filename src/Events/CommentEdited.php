<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A comment edited by its author. context: previous (body).
 */
final class CommentEdited extends Engagement
{
    public function signal(): string
    {
        return 'comment';
    }
}

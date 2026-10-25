<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

/**
 * A report closed by a moderator. context: status, resolution.
 */
final class ReportResolved extends Engagement
{
    public function signal(): string
    {
        return 'report';
    }

    public function positive(): bool
    {
        return false;
    }
}

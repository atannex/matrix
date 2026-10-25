<?php

declare(strict_types=1);

namespace Atannex\Matrix\Events;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Contracts\MatrixEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of every matrix event. $subject is the item engaged with, $actor who
 * did it, $record the row written (reaction, comment, …) when there is one.
 */
abstract class Engagement implements MatrixEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Model $subject,
        public readonly Actor $actor,
        public readonly ?Model $record = null,
        public readonly array $context = [],
    ) {}

    abstract public function signal(): string;

    public function positive(): bool
    {
        return true;
    }
}

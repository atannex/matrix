<?php

declare(strict_types=1);

namespace Atannex\Matrix\Contracts;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Subject;

/**
 * A model people can engage with. Implemented by the InteractsWithMatrix
 * trait; override acceptsEngagement() to close engagement (e.g. drafts) and
 * matrixSignals() to narrow which signals the model takes.
 */
interface Engageable
{
    /**
     * Whether the item takes this signal right now (null: any engagement).
     */
    public function acceptsEngagement(?string $signal = null): bool;

    /**
     * The signals this model takes, or null for every registered signal.
     *
     * @return array<int, string>|null
     */
    public function matrixSignals(): ?array;

    /**
     * Engage with this item as the given actor (the current one by default).
     */
    public function matrix(?Actor $actor = null): Subject;

    public function getMorphClass();

    public function getKey();
}

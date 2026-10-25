<?php

declare(strict_types=1);

namespace Atannex\Matrix\Contracts;

/**
 * Engagement content that moderators can hold back (comments, reviews).
 * Reports past the threshold hide it until someone looks at it.
 */
interface Moderatable
{
    public function hideForReview(): void;
}

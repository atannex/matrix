<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models\Concerns;

use Atannex\Matrix\Enums\Status;
use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;

/**
 * Anything moderators approve before it is public (comments, reviews, or
 * an app's testimonials): a `status` column cast to Status, and the states
 * ->approved(), ->pending(), ->rejected(), ->spam(), ->notApproved()…
 * (atannex/scopable). Leaving "approved" sends it back to pending.
 */
trait IsModerated
{
    use HasScopes;

    public function initializeIsModerated(): void
    {
        $this->mergeCasts(['status' => Status::class]);
    }

    public static function defineScopesIsModerated(Scopes $scopes): void
    {
        $scopes->equals('approved', 'status', Status::Approved, otherwise: Status::Pending);
        $scopes->equals('pending', 'status', Status::Pending);
        $scopes->equals('rejected', 'status', Status::Rejected);
        $scopes->equals('spam', 'status', Status::Spam);
    }

    public function isApproved(): bool
    {
        return $this->inState('approved');
    }

    public function isPending(): bool
    {
        return $this->inState('pending');
    }
}

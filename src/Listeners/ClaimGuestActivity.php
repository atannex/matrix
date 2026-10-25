<?php

declare(strict_types=1);

namespace Atannex\Matrix\Listeners;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Matrix;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On sign-in (including right after registration), what the browser did as
 * a guest becomes part of the account.
 */
final class ClaimGuestActivity
{
    public function __construct(
        private readonly Matrix $matrix,
    ) {}

    public function handle(Login $event): void
    {
        // Never let bookkeeping break a sign-in.
        try {
            $this->matrix->claim($this->matrix->actors()->guestKey(), Actor::forUser($event->user));
        } catch (Throwable $e) {
            Log::warning('Could not claim guest engagement on sign-in.', [
                'user_id' => $event->user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

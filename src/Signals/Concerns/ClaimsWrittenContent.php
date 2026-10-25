<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals\Concerns;

use Atannex\Matrix\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * Written content carries a public name, so a guest's posts only become the
 * account's when they were signed with the account's verified email.
 */
trait ClaimsWrittenContent
{
    public function identity(): ?array
    {
        return null;
    }

    public function transfer(string $guestKey, Actor $account): array
    {
        $email = $account->user instanceof Model ? $account->user->getAttribute('email') : null;

        if (! $account->isVerified() || ! is_string($email) || $email === '') {
            return [];
        }

        $this->base()
            ->where('actor_key', $guestKey)
            ->whereNull('user_id')
            ->whereRaw('lower(guest_email) = ?', [mb_strtolower($email)])
            ->update(['user_id' => $account->userId(), 'actor_key' => $account->key, 'updated_at' => now()]);

        return [];
    }
}

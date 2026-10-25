<?php

declare(strict_types=1);

namespace Atannex\Matrix\Contracts;

use Atannex\Matrix\Actor;

interface ResolvesActors
{
    /**
     * The actor making the current request (account when signed in, else guest).
     */
    public function current(): Actor;

    /**
     * This browser's guest key, whether or not someone is signed in. Issues
     * the actor cookie when the browser does not have one yet.
     */
    public function guestKey(): string;
}

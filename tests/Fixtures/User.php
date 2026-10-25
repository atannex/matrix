<?php

namespace Atannex\Matrix\Tests\Fixtures;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use \Illuminate\Auth\MustVerifyEmail;

    protected $guarded = [];

    protected $casts = ['email_verified_at' => 'datetime', 'moderator' => 'boolean'];
}

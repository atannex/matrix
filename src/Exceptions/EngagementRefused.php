<?php

declare(strict_types=1);

namespace Atannex\Matrix\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not send this signal to this item right now: engagement is
 * closed, the signal is not enabled for the model, guests must sign in, the
 * account is unverified, or it is not theirs to change. Renders as 403.
 */
final class EngagementRefused extends AuthorizationException
{
    public const CLOSED = 'closed';

    public const UNSUPPORTED = 'unsupported';

    public const SIGN_IN = 'sign_in';

    public const VERIFY_EMAIL = 'verify_email';

    public const FORBIDDEN = 'forbidden';

    public function __construct(string $message, public readonly string $reason = self::FORBIDDEN)
    {
        parent::__construct($message);
    }

    public static function closed(string $signal): self
    {
        return new self(__('This item is not open for :signal right now.', ['signal' => __($signal)]), self::CLOSED);
    }

    public static function unsupported(string $signal): self
    {
        return new self(__('This item does not take :signal.', ['signal' => __($signal)]), self::UNSUPPORTED);
    }

    public static function signIn(): self
    {
        return new self(__('Sign in to join in.'), self::SIGN_IN);
    }

    public static function verifyEmail(): self
    {
        return new self(__('Please verify your email address first.'), self::VERIFY_EMAIL);
    }

    public static function forbidden(): self
    {
        return new self(__('You are not allowed to do that.'), self::FORBIDDEN);
    }
}

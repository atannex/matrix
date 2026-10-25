<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Contracts\ResolvesActors;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The actor behind the current request. A guest without the cookie gets
 * exactly one new id per request however often it is asked for: the id is
 * kept on the request until the queued cookie comes back on the next one.
 */
final class ActorResolver implements ResolvesActors
{
    private const REQUEST_ATTRIBUTE = 'matrix.browser_id';

    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
        private readonly CookieJar $cookies,
    ) {}

    public function current(): Actor
    {
        $request = $this->request();
        $user = $this->auth->guard(config('matrix.guard'))->user();

        return new Actor(
            key: $user ? Actor::keyForUser($user) : $this->guestKey(),
            user: $user,
            sessionId: $request->hasSession() ? $request->session()->getId() : null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    public function guestKey(): string
    {
        $request = $this->request();

        if (! $request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $this->browserId($request));
        }

        return Actor::keyForGuest($request->attributes->get(self::REQUEST_ATTRIBUTE));
    }

    private function browserId(Request $request): string
    {
        $name = (string) config('matrix.cookie.name');
        $id = $request->cookie($name);

        if (is_string($id) && Str::isUuid($id)) {
            return $id;
        }

        $id = (string) Str::uuid();

        $this->cookies->queue($this->cookies->make(
            name: $name,
            value: $id,
            minutes: (int) config('matrix.cookie.lifetime'),
            secure: $request->isSecure() ?: null,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $id;
    }

    private function request(): Request
    {
        return $this->container->make('request');
    }
}

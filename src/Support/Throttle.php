<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Actor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Rate limits from config('matrix.limits'), per actor and per IP address,
 * so clearing cookies does not get a guest around the actor limit.
 */
final class Throttle
{
    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Record an attempt; false when the actor or their IP is over the limit.
     */
    public function attempt(string $action, Actor $actor): bool
    {
        return $this->retryAfter($action, $actor) === 0 && $this->hit($action, $actor);
    }

    /**
     * Record an attempt or throw a validation error on $field.
     *
     * @throws ValidationException
     */
    public function ensure(string $action, Actor $actor, string $field = 'matrix'): void
    {
        $seconds = $this->retryAfter($action, $actor);

        if ($seconds > 0) {
            throw ValidationException::withMessages([$field => trans_choice(
                'Slow down! You can try again in :seconds second.|Slow down! You can try again in :seconds seconds.',
                $seconds,
                ['seconds' => $seconds],
            )]);
        }

        $this->hit($action, $actor);
    }

    /**
     * Seconds until the actor may try again (0: now).
     */
    public function retryAfter(string $action, Actor $actor): int
    {
        foreach ($this->keys($action, $actor) as $scope => $key) {
            [$max] = $this->limit($action, $scope);

            if ($this->limiter->tooManyAttempts($key, $max)) {
                return max(1, $this->limiter->availableIn($key));
            }
        }

        return 0;
    }

    public function clear(string $action, Actor $actor): void
    {
        foreach ($this->keys($action, $actor) as $key) {
            $this->limiter->clear($key);
        }
    }

    private function hit(string $action, Actor $actor): bool
    {
        foreach ($this->keys($action, $actor) as $scope => $key) {
            [, $decay] = $this->limit($action, $scope);
            $this->limiter->hit($key, $decay);
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function keys(string $action, Actor $actor): array
    {
        return array_filter([
            'actor' => "matrix:{$action}:{$actor->key}",
            'ip' => $actor->ipAddress ? "matrix:{$action}:ip:{$actor->ipAddress}" : null,
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function limit(string $action, string $scope): array
    {
        $limit = config("matrix.limits.{$action}.{$scope}");

        return is_array($limit) ? [(int) $limit[0], (int) $limit[1]] : [PHP_INT_MAX, 60];
    }
}

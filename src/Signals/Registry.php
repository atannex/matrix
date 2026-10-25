<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use InvalidArgumentException;

/**
 * The signals the matrix knows about, built-in and custom.
 */
final class Registry
{
    /** @var array<string, Signal> */
    private array $signals = [];

    /**
     * @param  iterable<Signal>  $signals
     */
    public function __construct(iterable $signals = [])
    {
        foreach ($signals as $signal) {
            $this->register($signal);
        }
    }

    public function register(Signal $signal): void
    {
        $this->signals[$signal->name()] = $signal;
    }

    public function has(string $name): bool
    {
        return isset($this->signals[$name]);
    }

    public function get(string $name): Signal
    {
        return $this->signals[$name] ?? throw new InvalidArgumentException("Unknown matrix signal [{$name}].");
    }

    /**
     * @return array<string, Signal>
     */
    public function all(): array
    {
        return $this->signals;
    }

    /**
     * Whether some signal recounts this metric from raw rows.
     */
    public function recountable(string $metric): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal->owns($metric)) {
                return true;
            }
        }

        return false;
    }
}

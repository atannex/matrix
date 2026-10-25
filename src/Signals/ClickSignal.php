<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Events\Clicked;
use Illuminate\Database\Eloquent\Model;

/**
 * Click-throughs: someone opening the item from a listing. Repeat clicks by
 * the same actor inside "signals.click.debounce" seconds count once. Raw
 * rows are pruned, so the total is counter-only.
 *
 * Metrics: clicks, clicks.{placement}.
 */
class ClickSignal extends Signal
{
    public function name(): string
    {
        return 'click';
    }

    public function interactive(): bool
    {
        return false;
    }

    public function identity(): ?array
    {
        return null;
    }

    /**
     * @param  array{placement?: ?string, page?: ?string, referrer_host?: ?string}  $context
     */
    public function record(Model $item, Actor $actor, array $context = []): bool
    {
        if ($actor->isBot() || ! $this->matrix()->guard($item, $this->name(), $actor)) {
            return false;
        }

        $recent = $this->about($item)
            ->where('actor_key', $actor->key)
            ->where('created_at', '>=', now()->subSeconds((int) $this->config('debounce', 10)))
            ->exists();

        if ($recent) {
            return false;
        }

        $placement = $this->clean($context['placement'] ?? null, 80);

        $click = $this->newQuery()->create([
            ...$this->subjectKey($item),
            ...$actor->attributes([]),
            'placement' => $placement,
            'page' => $this->clean($context['page'] ?? null, 255),
            'referrer_host' => $this->clean($context['referrer_host'] ?? null, 255),
        ]);

        $amounts = ['clicks' => 1];

        if ($placement !== null && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $placement)) {
            $amounts["clicks.{$placement}"] = 1;
        }

        $this->counters()->increment($item, $amounts);
        $this->matrix()->dispatch(new Clicked($item, $actor, $click, $context));

        return true;
    }

    private function clean(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}

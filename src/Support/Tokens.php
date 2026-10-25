<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Contracts\Engageable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Signed, expiring tokens a page embeds per item and sends back with its
 * beacons and API calls: proof the request comes from a page this site
 * rendered for that item, so engagement cannot be posted for arbitrary ids.
 *
 * Token: base64url("{morph type}|{id}|{issued at}") "." base64url(HMAC-SHA256)
 */
final class Tokens
{
    public function __construct(
        private readonly string $key,
    ) {}

    public function issue(Model $item): string
    {
        $payload = $this->encode(implode('|', [$item->getMorphClass(), $item->getKey(), now()->getTimestamp()]));

        return $payload.'.'.$this->sign($payload);
    }

    /**
     * The item a valid, unexpired token was issued for (whether or not it
     * still accepts engagement), or null.
     */
    public function resolve(?string $token): (Model&Engageable)|null
    {
        [$payload, $signature] = array_pad(explode('.', (string) $token, 2), 2, '');

        if ($payload === '' || ! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        [$type, $id, $issuedAt] = array_pad(explode('|', (string) $this->decode($payload), 3), 3, null);

        $lifetime = (int) config('matrix.tokens.lifetime', 1440) * 60;

        if (! is_numeric($issuedAt) || (int) $issuedAt < now()->getTimestamp() - $lifetime) {
            return null;
        }

        $class = Relation::getMorphedModel((string) $type) ?? $type;

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, Model::class, true) || ! is_a($class, Engageable::class, true)) {
            return null;
        }

        $item = $class::query()->find($id);

        return $item instanceof Engageable ? $item : null;
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac('sha256', 'matrix|'.$payload, $this->key, true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}

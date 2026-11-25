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
 * Token: base64url(AES-256-GCM("{morph type}|{id}|{issued at}")) "." base64url(HMAC-SHA256)
 *
 * The payload is encrypted, so a page never reveals which model or key an
 * item has.
 */
final class Tokens
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly string $key,
    ) {}

    public function issue(Model $item): string
    {
        $payload = $this->encode($this->encrypt(implode('|', [$item->getMorphClass(), $item->getKey(), now()->getTimestamp()])));

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

        $decoded = $this->decode($payload);
        $plain = $decoded === false ? false : $this->decrypt($decoded);

        if ($plain === false) {
            return null;
        }

        [$type, $id, $issuedAt] = array_pad(explode('|', $plain, 3), 3, null);

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

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        return $iv.$tag.$cipher;
    }

    private function decrypt(string $value): string|false
    {
        if (strlen($value) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return false;
        }

        $iv = substr($value, 0, self::IV_LENGTH);
        $tag = substr($value, self::IV_LENGTH, self::TAG_LENGTH);

        return openssl_decrypt(substr($value, self::IV_LENGTH + self::TAG_LENGTH), self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    }

    /** A key of its own for encryption, derived from the signing key. */
    private function encryptionKey(): string
    {
        return hash_hmac('sha256', 'matrix|encrypt', $this->key, true);
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

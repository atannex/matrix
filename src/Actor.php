<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use Atannex\Detector\Detector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whoever engages: a signed-in account or a guest browser. The key is what
 * every engagement row is de-duplicated on:
 *
 *     account → "user_{id}"
 *     guest   → "guest_{uuid from the actor cookie}"
 */
final readonly class Actor
{
    public function __construct(
        public string $key,
        public ?Authenticatable $user = null,
        public ?string $sessionId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * An account acting outside a request (jobs, imports, tests).
     */
    public static function forUser(Authenticatable $user, ?string $ipAddress = null, ?string $userAgent = 'Matrix/CLI'): self
    {
        return new self(self::keyForUser($user), $user, null, $ipAddress, $userAgent);
    }

    /**
     * A guest outside a request; a random browser id when none is given.
     */
    public static function guest(?string $browserId = null, ?string $ipAddress = null, ?string $userAgent = 'Matrix/CLI'): self
    {
        return new self(self::keyForGuest($browserId ?? (string) Str::uuid()), null, null, $ipAddress, $userAgent);
    }

    public static function keyForUser(Authenticatable|int|string $user): string
    {
        return 'user_'.($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
    }

    public static function keyForGuest(string $browserId): string
    {
        return 'guest_'.$browserId;
    }

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    public function userId(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    /**
     * Whether the account has confirmed its email address. Accounts whose
     * model cannot verify email count as verified; guests never do.
     */
    public function isVerified(): bool
    {
        if ($this->user === null) {
            return false;
        }

        return ! method_exists($this->user, 'hasVerifiedEmail') || $this->user->hasVerifiedEmail();
    }

    public function is(?self $other): bool
    {
        return $other !== null && $other->key === $this->key;
    }

    /**
     * Crawlers, link previews, monitors and scripts are never counted. With
     * atannex/detector installed its bot list decides (200+ named bots,
     * headless browsers and HTTP clients); otherwise a name pattern does.
     */
    public function isBot(): bool
    {
        if (config('matrix.privacy.count_bots')) {
            return false;
        }

        if ($this->userAgent === null || trim($this->userAgent) === '') {
            return true;
        }

        if (config('matrix.privacy.bot_detection', 'auto') === 'auto' && class_exists(Detector::class) && app()->bound(Detector::class)) {
            return app(Detector::class)->parse($this->userAgent)->isBot();
        }

        return (bool) preg_match(
            '/bot|crawl|spider|slurp|scrap|preview|facebookexternalhit|whatsapp|telegram|discord|slack|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python-requests|httpclient|go-http|java\//i',
            $this->userAgent,
        );
    }

    /**
     * The IP with its host part zeroed (last IPv4 octet, last 80 IPv6 bits)
     * when anonymisation is on.
     */
    public function anonymizedIp(): ?string
    {
        if ($this->ipAddress === null || ! config('matrix.privacy.anonymize_ip')) {
            return $this->ipAddress;
        }

        return IpUtils::anonymize($this->ipAddress);
    }

    /**
     * The columns every engagement row stores about who created it.
     *
     * @param  array<int, string>  $columns  which optional columns the table has
     * @return array<string, mixed>
     */
    public function attributes(array $columns = ['session_id'], bool $fullIp = false): array
    {
        $attributes = [
            'user_id' => $this->userId(),
            'actor_key' => $this->key,
            'ip_address' => $fullIp ? $this->ipAddress : $this->anonymizedIp(),
        ];

        if (in_array('session_id', $columns, true)) {
            $attributes['session_id'] = $this->sessionId;
        }

        if (in_array('user_agent', $columns, true)) {
            $attributes['user_agent'] = $this->userAgent === null ? null : mb_substr($this->userAgent, 0, 255);
        }

        return $attributes;
    }

    /**
     * The account's display name, or null for guests.
     */
    public function name(): ?string
    {
        if (! $this->user instanceof Model) {
            return null;
        }

        return $this->user->getAttribute('name');
    }
}

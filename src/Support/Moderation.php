<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Enums\Status;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who may write (comments, reviews), what is held for a moderator, what is
 * spam, and who moderates.
 *
 *   Verified account   → published at once (unless moderate_all)
 *   Unverified account → must verify their email first (verified_only)
 *   Guest              → name + email; held for moderation (moderate_guests)
 *   Held account       → held for moderation (holdUsing)
 *   Too many links     → held for moderation
 *   Blocked word       → spam
 */
final class Moderation
{
    private ?Closure $moderators = null;

    private ?Closure $held = null;

    public function __construct(
        private readonly Gate $gate,
    ) {}

    /**
     * Decide who counts as a moderator, e.g. fn ($user) => $user->isModerator().
     * The callback also receives the comment or review being moderated, when
     * there is one, so comments and reviews can have different moderators.
     * Default: the "matrix.moderate" gate ability.
     */
    public function moderatorsUsing(?Closure $callback): void
    {
        $this->moderators = $callback;
    }

    /**
     * Hold everything some signed-in users write for a moderator, e.g.
     * fn ($user) => $user->isRestricted(). Receives the user.
     */
    public function holdUsing(?Closure $callback): void
    {
        $this->held = $callback;
    }

    public function isModerator(?Actor $actor, ?Model $content = null): bool
    {
        if ($actor?->user === null) {
            return false;
        }

        if ($this->moderators !== null) {
            return (bool) ($this->moderators)($actor->user, $content);
        }

        return $this->gate->forUser($actor->user)->allows('matrix.moderate');
    }

    /**
     * Why this actor may not write a $kind ("comment", "review") right now,
     * or null when they may.
     */
    public function restriction(string $kind, Actor $actor, bool $guestsAllowed): ?string
    {
        return $this->refusal($kind, $actor, $guestsAllowed)?->getMessage();
    }

    /**
     * @throws EngagementRefused
     */
    public function ensureMayWrite(string $kind, Actor $actor, bool $guestsAllowed): void
    {
        if ($refusal = $this->refusal($kind, $actor, $guestsAllowed)) {
            throw $refusal;
        }
    }

    private function refusal(string $kind, Actor $actor, bool $guestsAllowed): ?EngagementRefused
    {
        if ($actor->isGuest()) {
            return $guestsAllowed ? null : EngagementRefused::signIn();
        }

        if ($this->config($kind, 'verified_only', true) && ! $actor->isVerified()) {
            return EngagementRefused::verifyEmail();
        }

        return null;
    }

    /**
     * Whether this actor's $kind posts wait for a moderator before review
     * of the text itself.
     */
    public function holdsFor(string $kind, Actor $actor): bool
    {
        if ($this->isModerator($actor)) {
            return false;
        }

        if ($actor->user !== null && $this->held !== null && ($this->held)($actor->user)) {
            return true;
        }

        return (bool) $this->config($kind, 'moderate_all', false)
            || ($actor->isGuest() && (bool) $this->config($kind, 'moderate_guests', true));
    }

    /**
     * Validate the text and decide its starting status.
     *
     * @throws ValidationException
     */
    public function verdict(string $kind, Actor $actor, string $body, string $field = 'body'): Status
    {
        $length = mb_strlen($body);
        $min = (int) $this->config($kind, 'min_length', 1);
        $max = (int) $this->config($kind, 'max_length', 5000);

        if ($length < $min || $length > $max) {
            throw ValidationException::withMessages([$field => __('Write between :min and :max characters.', ['min' => $min, 'max' => $max])]);
        }

        if ($this->containsBlockedWord($kind, $body)) {
            return Status::Spam;
        }

        if ($this->holdsFor($kind, $actor) || $this->linkCount($body) > (int) $this->config($kind, 'max_links', PHP_INT_MAX)) {
            return Status::Pending;
        }

        return Status::Approved;
    }

    public function linkCount(string $body): int
    {
        return preg_match_all('~(https?://|www\.)\S+~i', $body);
    }

    private function containsBlockedWord(string $kind, string $body): bool
    {
        $words = array_filter((array) $this->config($kind, 'blocked_words', []));

        return $words !== [] && Str::contains(Str::lower($body), array_map(Str::lower(...), $words));
    }

    private function config(string $kind, string $key, mixed $default): mixed
    {
        return config("matrix.{$kind}s.{$key}", $default);
    }
}

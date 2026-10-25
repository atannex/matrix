<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models\Concerns;

use Atannex\Matrix\Actor;
use Atannex\Scopable\Concerns\HasScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Shared by every engagement row: the item it is about ("engageable"), the
 * account behind it (when signed in) and the actor key it is de-duplicated
 * on. Table and connection come from config/matrix.php.
 *
 * Subclasses set MATRIX_KEY to their key in config('matrix.tables').
 */
trait IsMatrixRecord
{
    /** Date scopes (createdToday(), createdWithin('7 days')…) from atannex/scopable. */
    use HasScopes;

    public function initializeIsMatrixRecord(): void
    {
        $this->makeHidden(['actor_key', 'session_id', 'ip_address', 'user_agent']);
    }

    public function getTable(): string
    {
        return $this->table ?? (string) config('matrix.tables.'.static::MATRIX_KEY);
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('matrix.connection');
    }

    public function engageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('matrix.user_model'));
    }

    public function scopeBy(Builder $query, Actor $actor): Builder
    {
        return $query->where($query->qualifyColumn('actor_key'), $actor->key);
    }

    public function scopeGuests(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('user_id'));
    }

    public function scopeAccounts(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('user_id'));
    }

    public function isGuest(): bool
    {
        return $this->getAttribute('user_id') === null;
    }

    public function isBy(Actor $actor): bool
    {
        return $this->getAttribute('actor_key') === $actor->key;
    }
}

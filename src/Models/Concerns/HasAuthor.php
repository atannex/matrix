<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models\Concerns;

use Atannex\Matrix\Actor;
use Atannex\Scopable\Period;
use Illuminate\Database\Eloquent\Builder;

/**
 * Written content (comments, reviews): public author name, moderation
 * (IsModerated), edit window.
 */
trait HasAuthor
{
    use IsModerated;

    public function initializeHasAuthor(): void
    {
        $this->mergeHidden(['guest_email']);
        $this->mergeCasts(['edited_at' => 'datetime']);
    }

    /**
     * What this actor may see: everything approved, plus their own pending posts.
     */
    public function scopeVisibleTo(Builder $query, ?Actor $actor): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->approved()
            ->when($actor, fn (Builder $q) => $q->orWhere(fn (Builder $own) => $own
                ->pending()
                ->where($own->qualifyColumn('actor_key'), $actor->key))));
    }

    public function authorName(): string
    {
        return $this->user?->getAttribute('name') ?? $this->getAttribute('guest_name') ?? __('Guest');
    }

    public function isWrittenBy(Actor $actor): bool
    {
        return $this->getAttribute('user_id') !== null
            ? (string) $this->getAttribute('user_id') === (string) $actor->userId()
            : $this->getAttribute('actor_key') === $actor->key;
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * Whether the author may still change it (config "edit_window" minutes).
     */
    public function withinEditWindow(): bool
    {
        $minutes = (int) config('matrix.'.static::MATRIX_KEY.'s.edit_window', 0);

        return $minutes > 0
            && Period::within("{$minutes} minutes")->contains($this->created_at)
            && ($this->inState('approved') || $this->inState('pending'));
    }

    public function hideForReview(): void
    {
        if ($this->inState('approved')) {
            $this->leaveState('approved');
        }
    }
}

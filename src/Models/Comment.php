<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Contracts\Moderatable;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Models\Concerns\HasAuthor;
use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A comment or reply by an account (user_id) or a guest (guest_name /
 * guest_email). Comments are engageable themselves: they collect
 * reactions and reports.
 *
 *   Top-level:         parent_id = null,       root_id = null,     depth 0
 *   Reply:             parent_id = parent id,  root_id = top id,   depth 1..max
 */
class Comment extends Model implements Engageable, Moderatable
{
    use HasAuthor;
    use InteractsWithMatrix;
    use IsMatrixRecord;

    public const MATRIX_KEY = 'comment';

    protected $guarded = ['id'];

    protected $casts = [
        'depth' => 'integer',
        'pinned_at' => 'datetime',
    ];

    /**
     * Keep the commented item's totals in step however the comment changes
     * (posted, moderated in an admin panel, deleted).
     */
    protected static function booted(): void
    {
        static::saved(function (Comment $comment) {
            if (($comment->wasRecentlyCreated && $comment->getChanges() === []) || $comment->wasChanged('status')) {
                Matrix::commentsChanged($comment);
            }
        });

        static::deleted(fn (Comment $comment) => Matrix::commentsChanged($comment));
    }

    public function matrixSignals(): ?array
    {
        return ['reaction', 'report'];
    }

    public function acceptsEngagement(?string $signal = null): bool
    {
        return $this->isApproved();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function root(): BelongsTo
    {
        return $this->belongsTo(static::class, 'root_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->oldest();
    }

    public function thread(): HasMany
    {
        return $this->hasMany(static::class, 'root_id')->oldest();
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('parent_id'));
    }

    public function scopePinnedFirst(Builder $query): Builder
    {
        return $query->orderByRaw($query->qualifyColumn('pinned_at').' is null')->orderByDesc($query->qualifyColumn('pinned_at'));
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }
}

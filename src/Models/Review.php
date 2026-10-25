<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Contracts\Moderatable;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\Models\Concerns\HasAuthor;
use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A written review with an optional score, one per actor per item. Reviews
 * are engageable themselves: readers mark them helpful (reactions) or
 * report them.
 */
class Review extends Model implements Engageable, Moderatable
{
    use HasAuthor;
    use InteractsWithMatrix;
    use IsMatrixRecord;
    use SoftDeletes;

    public const MATRIX_KEY = 'review';

    protected $guarded = ['id'];

    protected $casts = [
        'rating' => 'integer',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        $sync = fn (Review $review) => Matrix::reviewsChanged($review);

        static::saved(function (Review $review) use ($sync) {
            if (($review->wasRecentlyCreated && $review->getChanges() === []) || $review->wasChanged(['status', 'rating'])) {
                $sync($review);
            }
        });

        static::deleted($sync);
        static::restored($sync);
    }

    public function matrixSignals(): ?array
    {
        return ['reaction', 'report'];
    }

    public function acceptsEngagement(?string $signal = null): bool
    {
        return $this->isApproved();
    }

    /**
     * Readers who found it helpful.
     */
    public function helpfulCount(): int
    {
        return $this->matrix()->stats()->reaction('helpful');
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One actor's score for one item on one dimension ("overall" by default).
 */
class Rating extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'rating';

    public const OVERALL = 'overall';

    protected $guarded = ['id'];

    protected $attributes = ['dimension' => self::OVERALL];

    protected $casts = ['value' => 'integer'];

    public function scopeDimension(Builder $query, string $dimension = self::OVERALL): Builder
    {
        return $query->where($query->qualifyColumn('dimension'), $dimension);
    }
}

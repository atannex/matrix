<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An item saved by an actor, in a named collection ("default", "read later"…).
 */
class Bookmark extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'bookmark';

    public const DEFAULT_COLLECTION = 'default';

    protected $guarded = ['id'];

    protected $attributes = ['collection' => self::DEFAULT_COLLECTION];

    public function scopeInCollection(Builder $query, string $collection): Builder
    {
        return $query->where($query->qualifyColumn('collection'), $collection);
    }
}

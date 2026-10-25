<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An actor following an item (an author, a topic, a story) for updates.
 */
class Follow extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'follow';

    protected $guarded = ['id'];

    protected $attributes = ['notify' => true];

    protected $casts = ['notify' => 'boolean'];

    public function scopeNotifiable(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('notify'), true);
    }
}

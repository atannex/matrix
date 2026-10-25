<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One actor's reaction (like, dislike, love…) to one item.
 */
class Reaction extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'reaction';

    protected $guarded = ['id'];

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($query->qualifyColumn('type'), $type);
    }

    public function emoji(): ?string
    {
        return config("matrix.reactions.{$this->type}.emoji");
    }
}

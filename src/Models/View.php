<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One actor's views of one item: how often, and when last (a reload inside
 * the view window is not a new view).
 */
class View extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'view';

    protected $guarded = ['id'];

    protected $casts = [
        'view_count' => 'integer',
        'last_viewed_at' => 'datetime',
    ];
}

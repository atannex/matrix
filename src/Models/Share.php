<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One actor sharing one item to one platform, with how many times.
 */
class Share extends Model
{
    use IsMatrixRecord;

    public const MATRIX_KEY = 'share';

    protected $guarded = ['id'];

    protected $casts = ['share_count' => 'integer'];
}

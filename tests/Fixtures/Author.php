<?php

namespace Atannex\Matrix\Tests\Fixtures;

use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;
use Illuminate\Database\Eloquent\Model;

/**
 * Takes follows only.
 */
class Author extends Model implements Engageable
{
    use InteractsWithMatrix;

    protected $table = 'writers';

    protected $guarded = [];

    public function matrixSignals(): ?array
    {
        return ['follow'];
    }
}

<?php

namespace Atannex\Matrix\Tests\Fixtures;

use Atannex\Matrix\Concerns\InteractsWithMatrix;
use Atannex\Matrix\Contracts\Engageable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model implements Engageable
{
    use InteractsWithMatrix;
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = ['published' => true];

    protected $casts = ['published' => 'boolean', 'published_at' => 'datetime'];

    public function acceptsEngagement(?string $signal = null): bool
    {
        return $this->published;
    }
}

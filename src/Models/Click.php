<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone opening the item from a listing: where (placement, page) and from
 * which site. Raw rows are pruned after "signals.click.retention" days; the
 * totals stay in the counters.
 */
class Click extends Model
{
    use IsMatrixRecord;
    use MassPrunable;

    public const MATRIX_KEY = 'click';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('matrix.signals.click.retention', 90)));
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Matrix\Models;

use Atannex\Matrix\Enums\ReportStatus;
use Atannex\Matrix\Models\Concerns\IsMatrixRecord;
use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An actor flagging an item for moderators, with a reason.
 */
class Report extends Model
{
    use HasScopes;
    use IsMatrixRecord;

    public const MATRIX_KEY = 'report';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected $casts = [
        'status' => ReportStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(config('matrix.user_model'), 'resolved_by');
    }

    /**
     * ->open(), ->resolved(), ->dismissed(), ->notOpen() (atannex/scopable).
     */
    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->equals('open', 'status', ReportStatus::Open);
        $scopes->equals('resolved', 'status', ReportStatus::Resolved);
        $scopes->equals('dismissed', 'status', ReportStatus::Dismissed);
        $scopes->date('resolved', 'resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->inState('open');
    }
}

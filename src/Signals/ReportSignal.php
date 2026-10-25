<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Contracts\Moderatable;
use Atannex\Matrix\Enums\ReportStatus;
use Atannex\Matrix\Events\Reported;
use Atannex\Matrix\Events\ReportResolved;
use Atannex\Matrix\Exceptions\EngagementRefused;
use Atannex\Matrix\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Flagging an item for moderators. An item that can be moderated (comment,
 * review) is hidden once "reports.hide_threshold" open reports pile up.
 *
 * Metrics: reports (open reports).
 */
class ReportSignal extends Signal
{
    public function name(): string
    {
        return 'report';
    }

    public function owns(string $metric): bool
    {
        return $metric === 'reports';
    }

    public function recount(string $type, int|string $id): array
    {
        $count = $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->where('status', ReportStatus::Open->value)->count();

        return $count > 0 ? ['reports' => $count] : [];
    }

    public function has(Model $item, Actor $actor): bool
    {
        return $this->about($item)->where('actor_key', $actor->key)->where('status', ReportStatus::Open->value)->exists();
    }

    public function report(Model $item, Actor $actor, string $reason, ?string $details = null): Report
    {
        $reasons = (array) config('matrix.reports.reasons', []);

        if ($reasons !== [] && ! in_array($reason, $reasons, true)) {
            throw ValidationException::withMessages(['reason' => __('Choose a reason for the report.')]);
        }

        // Moderators deal with content directly; anyone else needs the item open.
        if (! ($item instanceof Moderatable && $this->matrix()->isModerator($actor))) {
            $this->guardReportable($item, $actor);
        }

        $report = $this->newQuery()->updateOrCreate(
            $this->subjectKey($item) + ['actor_key' => $actor->key],
            [...$actor->attributes(fullIp: true), 'reason' => $reason, 'details' => $details === null ? null : mb_substr(trim($details), 0, 2000),
                'status' => ReportStatus::Open, 'resolved_by' => null, 'resolved_at' => null, 'resolution' => null],
        );

        $this->refresh($item);

        if ($report->wasRecentlyCreated) {
            $this->counters()->increment($item, [], ['reports' => 1]);
        }

        $hidden = false;
        $threshold = (int) config('matrix.reports.hide_threshold', 0);

        if ($item instanceof Moderatable && $threshold > 0 && $this->about($item)->where('status', ReportStatus::Open->value)->count() >= $threshold) {
            $item->hideForReview();
            $hidden = true;
        }

        $this->matrix()->dispatch(new Reported($item, $actor, $report, ['reason' => $reason, 'hidden' => $hidden]));

        return $report;
    }

    /**
     * Close a report (moderators only).
     */
    public function resolve(Report $report, Actor $moderator, ReportStatus $status = ReportStatus::Resolved, ?string $resolution = null): Report
    {
        if (! $this->matrix()->isModerator($moderator)) {
            throw EngagementRefused::forbidden();
        }

        $report->forceFill([
            'status' => $status,
            'resolved_by' => $moderator->userId(),
            'resolved_at' => now(),
            'resolution' => $resolution,
        ])->save();

        $this->counters()->refresh($report->engageable_type, $report->engageable_id, $this);

        if ($subject = $report->engageable) {
            $this->matrix()->dispatch(new ReportResolved($subject, $moderator, $report, ['status' => $status, 'resolution' => $resolution]));
        }

        return $report;
    }

    private function guardReportable(Model $item, Actor $actor): void
    {
        // Reports about hidden content still count: it is what they are for.
        if ($item instanceof Moderatable) {
            $this->matrix()->guard($item, $this->name(), $actor, ignoreClosed: true);

            return;
        }

        $this->matrix()->guard($item, $this->name(), $actor);
    }
}

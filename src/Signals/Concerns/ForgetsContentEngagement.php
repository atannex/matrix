<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals\Concerns;

/**
 * Rows that are engageable themselves (comments, reviews) collect their own
 * reactions, reports and totals. When the item they are about, or their
 * author's account, is deleted, those go with them instead of being left behind.
 */
trait ForgetsContentEngagement
{
    public function forget(string $type, int|string $id): void
    {
        if ($this->table() === null || $this->model() === null) {
            return;
        }

        $contentType = (new ($this->model()))->getMorphClass();

        foreach ($this->base($type)->where('engageable_type', $type)->where('engageable_id', $id)->pluck('id') as $contentId) {
            $this->matrix()->forgetKey($contentType, $contentId);
        }

        parent::forget($type, $id);
    }

    /**
     * A deleted account's posts go with it, each through its model so its
     * replies, its own engagement and the item's totals follow.
     */
    public function forgetUser(int|string $userId): array
    {
        if ($this->table() === null || $this->model() === null) {
            return [];
        }

        foreach ($this->connections() as $connection) {
            foreach ($this->db($connection)->where('user_id', $userId)->pluck('id') as $id) {
                $content = (new ($this->model()))->setConnection($connection)->newQueryWithoutScopes()->find($id);

                // Already gone as a reply to an earlier one.
                if ($content === null) {
                    continue;
                }

                method_exists($content, 'forceDelete') ? $content->forceDelete() : $content->delete();
            }
        }

        return [];
    }
}

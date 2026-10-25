<?php

declare(strict_types=1);

namespace Atannex\Matrix\Contracts;

/**
 * Maps a clicked link on the site back to the item it opens, so click
 * beacons can send the link's address instead of a token (config
 * matrix.beacon.click_targets).
 */
interface ResolvesClickTargets
{
    /**
     * The item at this address (path or absolute URL), or null when the link
     * is not to trackable content.
     */
    public function resolve(string $url): ?Engageable;
}

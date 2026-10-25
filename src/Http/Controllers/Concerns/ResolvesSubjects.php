<?php

declare(strict_types=1);

namespace Atannex\Matrix\Http\Controllers\Concerns;

use Atannex\Matrix\Matrix;
use Atannex\Matrix\Subject;

trait ResolvesSubjects
{
    /**
     * The item a token was issued for, as the current actor.
     */
    protected function subject(Matrix $matrix, string $token): Subject
    {
        $item = $matrix->tokens()->resolve($token);

        // A token outlives its page: once the item is closed (e.g. a story
        // unpublished or archived) its stats and threads are gone too.
        abort_if($item === null || ! $item->acceptsEngagement(), 404);

        return $matrix->for($item);
    }
}

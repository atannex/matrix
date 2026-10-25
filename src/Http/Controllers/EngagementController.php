<?php

declare(strict_types=1);

namespace Atannex\Matrix\Http\Controllers;

use Atannex\Matrix\Author;
use Atannex\Matrix\Http\Controllers\Concerns\ResolvesSubjects;
use Atannex\Matrix\Matrix;
use Atannex\Matrix\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON API over one item, addressed by its tracking token. Every answer
 * carries the item's fresh totals and the actor's state, so a client can
 * re-render its buttons from any response.
 */
final class EngagementController extends Controller
{
    use ResolvesSubjects;

    public function __construct(
        private readonly Matrix $matrix,
        private readonly Presenter $presenter,
    ) {}

    public function show(string $token): JsonResponse
    {
        return $this->respond($token);
    }

    public function react(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:32'], 'toggle' => ['sometimes', 'boolean']]);
        $subject = $this->subject($this->matrix, $token)->throttled('type');

        $request->boolean('toggle', true) ? $subject->toggleReaction($data['type']) : $subject->react($data['type']);

        return $this->respond($token);
    }

    public function unreact(string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->throttled('type')->unreact();

        return $this->respond($token);
    }

    public function rate(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'integer'],
            'dimension' => ['sometimes', 'string', 'max:40'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->subject($this->matrix, $token)->throttled('value')->rate((int) $data['value'], $data['dimension'] ?? 'overall', $data['comment'] ?? null);

        return $this->respond($token);
    }

    public function unrate(Request $request, string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->throttled('value')->unrate($request->string('dimension', 'overall')->toString());

        return $this->respond($token);
    }

    public function reviews(Request $request, string $token): JsonResponse
    {
        $page = $this->subject($this->matrix, $token)->reviews(min(50, max(1, $request->integer('per_page', 10))), $request->string('sort', 'helpful')->toString());
        $actor = $this->matrix->actor();

        return response()->json([
            'data' => collect($page->items())->map(fn ($review) => $this->presenter->review($review, $actor))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function review(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'rating' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'guest_name' => ['nullable', 'string'],
            'guest_email' => ['nullable', 'string'],
        ]);

        $subject = $this->subject($this->matrix, $token)->throttled('body');
        $guest = $subject->actor()->isGuest() ? Author::make($data['guest_name'] ?? null, $data['guest_email'] ?? null) : null;

        $review = $subject->review($data['body'], isset($data['rating']) ? (int) $data['rating'] : null, $data['title'] ?? null, $guest);

        return $this->respond($token, ['review' => $this->presenter->review($review, $subject->actor())], 201);
    }

    public function unreview(string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->unreview();

        return $this->respond($token);
    }

    public function comments(Request $request, string $token): JsonResponse
    {
        $subject = $this->subject($this->matrix, $token);
        $limit = min(50, max(1, $request->integer('limit', 20)));

        return response()->json([
            'data' => $subject->comments($limit, max(0, $request->integer('offset')))->map(fn ($c) => $this->presenter->comment($c, $subject->actor()))->all(),
            'meta' => ['count' => $subject->commentCount(), 'restriction' => $subject->commentRestriction()],
        ]);
    }

    public function comment(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'reply_to' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string'],
            'guest_email' => ['nullable', 'string'],
        ]);

        $subject = $this->subject($this->matrix, $token)->throttled('body');
        $guest = $subject->actor()->isGuest() ? Author::make($data['guest_name'] ?? null, $data['guest_email'] ?? null) : null;

        $comment = $subject->comment($data['body'], $data['reply_to'] ?? null, $guest);

        return $this->respond($token, ['comment' => $this->presenter->comment($comment, $subject->actor())], 201);
    }

    public function editComment(Request $request, string $token, int $comment): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string']]);
        $subject = $this->subject($this->matrix, $token)->throttled('body');

        return $this->respond($token, ['comment' => $this->presenter->comment($subject->editComment($comment, $data['body']), $subject->actor())]);
    }

    public function deleteComment(string $token, int $comment): JsonResponse
    {
        $this->subject($this->matrix, $token)->deleteComment($comment);

        return $this->respond($token);
    }

    public function likeComment(string $token, int $comment): JsonResponse
    {
        $subject = $this->subject($this->matrix, $token)->throttled('comment');
        $liked = $subject->toggleCommentLike($comment);

        return response()->json(['liked' => $liked, 'likes' => $subject->findComment($comment)->matrixStats()->likes()]);
    }

    public function share(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', 'string', 'max:40']]);

        $this->subject($this->matrix, $token)->throttled('platform')->share($data['platform']);

        return $this->respond($token);
    }

    public function bookmark(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['collection' => ['sometimes', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:500']]);

        $this->subject($this->matrix, $token)->throttled('collection')->bookmark($data['collection'] ?? 'default', $data['note'] ?? null);

        return $this->respond($token);
    }

    public function unbookmark(Request $request, string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->throttled('collection')->unbookmark($request->input('collection'));

        return $this->respond($token);
    }

    public function follow(Request $request, string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->throttled('follow')->follow($request->boolean('notify', true));

        return $this->respond($token);
    }

    public function unfollow(string $token): JsonResponse
    {
        $this->subject($this->matrix, $token)->throttled('follow')->unfollow();

        return $this->respond($token);
    }

    public function report(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40'], 'details' => ['nullable', 'string', 'max:2000']]);

        $this->subject($this->matrix, $token)->throttled('reason')->report($data['reason'], $data['details'] ?? null);

        return $this->respond($token, [], 201);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(string $token, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json($this->presenter->subject($this->subject($this->matrix, $token)) + $extra, $status);
    }
}

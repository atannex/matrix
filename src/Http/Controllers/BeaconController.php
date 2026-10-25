<?php

declare(strict_types=1);

namespace Atannex\Matrix\Http\Controllers;

use Atannex\Matrix\Contracts\Engageable;
use Atannex\Matrix\Contracts\ResolvesClickTargets;
use Atannex\Matrix\Matrix;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * Tracking beacons sent by matrix.js (navigator.sendBeacon): page views,
 * engaged reading time, click-throughs. Always a bodiless answer, and a bare
 * 422 rather than a redirect when the input is invalid.
 */
final class BeaconController extends Controller
{
    public function view(Request $request, Matrix $matrix): Response
    {
        return $this->guard($request, ['token' => ['required', 'string', 'max:512']], fn () => $this->track(
            $matrix,
            $matrix->tokens()->resolve($request->string('token')->toString()),
            fn ($subject) => $subject->view(),
        ));
    }

    public function read(Request $request, Matrix $matrix): Response
    {
        $rules = [
            'token' => ['required', 'string', 'max:512'],
            'seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'first' => ['sometimes', 'boolean'],
        ];

        return $this->guard($request, $rules, fn (array $data) => $this->track(
            $matrix,
            $matrix->tokens()->resolve((string) $data['token']),
            fn ($subject) => $subject->read((int) $data['seconds'], (bool) ($data['first'] ?? true)),
        ));
    }

    /**
     * A click-through, addressed by the item's token or, when the app binds
     * matrix.beacon.click_targets, by the link's url.
     */
    public function click(Request $request, Matrix $matrix): Response
    {
        $rules = [
            'token' => ['required_without:url', 'nullable', 'string', 'max:512'],
            'url' => ['required_without:token', 'nullable', 'string', 'max:2048'],
            'placement' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9 _\-:.\/]+$/'],
            'page' => ['nullable', 'string', 'max:255'],
        ];

        return $this->guard($request, $rules, function (array $data) use ($request, $matrix) {
            $referrer = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
            $page = isset($data['page']) ? parse_url((string) $data['page'], PHP_URL_PATH) : null;

            $context = [
                'placement' => $data['placement'] ?? null,
                // Path only: query strings can carry personal data.
                'page' => is_string($page) && $page !== '' ? mb_substr($page, 0, 255) : null,
                'referrer_host' => is_string($referrer) ? $referrer : null,
            ];

            return $this->track($matrix, $this->clickTarget($matrix, $data), fn ($subject) => $subject->click($context));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function clickTarget(Matrix $matrix, array $data): ?Engageable
    {
        if (! empty($data['token'])) {
            return $matrix->tokens()->resolve((string) $data['token']);
        }

        $class = config('matrix.beacon.click_targets');
        $resolver = $class ? app($class) : null;

        return $resolver instanceof ResolvesClickTargets ? $resolver->resolve((string) $data['url']) : null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  callable(array<string, mixed>): Response  $then
     */
    private function guard(Request $request, array $rules, callable $then): Response
    {
        $validator = Validator::make($request->all(), $rules);

        return $validator->fails() ? response()->noContent(422) : $then($validator->validated());
    }

    private function track(Matrix $matrix, ?Engageable $item, callable $record): Response
    {
        if ($item === null) {
            return response()->noContent(config('matrix.beacon.silent') ? 204 : 404);
        }

        $actor = $matrix->actor();

        if (! $matrix->throttle()->attempt('beacon', $actor)) {
            return response()->noContent(429);
        }

        $record($matrix->for($item, $actor));

        return response()->noContent();
    }
}

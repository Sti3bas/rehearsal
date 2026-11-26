<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class RouteMocks
{
    public static function register(Attempt $attempt, array $input, bool $fault): void
    {
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 128_000, 422);
        $data = Validator::validate($input, [
            'path' => ['required', 'string', 'max:1024', 'starts_with:/', 'not_regex:/^\/\//'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'response' => 'required|array:status,jsonBody,body,headers,delay,abort,networkError,once',
            'response.status' => 'sometimes|integer|min:200|max:599',
            'response.jsonBody' => 'sometimes|string|json|max:100000',
            'response.body' => 'sometimes|string|max:100000',
            'response.headers' => 'sometimes|array|max:20',
            'response.headers.*' => 'required|string|max:1024',
            'response.delay' => 'sometimes|integer|min:0|max:10000',
            'response.abort' => 'sometimes|boolean',
            'response.networkError' => 'sometimes|boolean',
            'response.once' => 'sometimes|boolean',
        ]);
        abort_if(isset($data['response']['body'], $data['response']['jsonBody']), 422);
        foreach ($data['response']['headers'] ?? [] as $name => $value) {
            abort_unless(preg_match('/\A[a-zA-Z0-9!#$%&\'*+.^_`|~-]+\z/', $name)
                && ! preg_match('/[\r\n]/', $value), 422);
        }
        if ($fault) {
            abort_unless(in_array($data['path'], config('rehearsal.fault_paths', []), true), 422);
            abort_if(! empty($data['response']['abort']) || ! empty($data['response']['networkError']), 422);
        }

        AttemptData::update($attempt, $fault ? 'route-faults' : 'route-mocks', function (array &$rules) use ($data): void {
            abort_if(count($rules) >= 64, 422);
            $rules[] = $data;
        });
    }

    public function resolve(Request $request, Attempt $attempt): JsonResponse
    {
        abort_unless($request->hasHeader(Protocol::MARKER), 403);
        $input = $request->validate(['path' => 'required|string|max:4096', 'method' => 'required|string|max:16']);

        return response()->json(['data' => self::claim($attempt, $input['method'], $input['path'])])
            ->header('Cache-Control', 'no-store');
    }

    public static function fault(Attempt $attempt, Request $request): ?Response
    {
        $rule = self::claim($attempt, $request->method(), $request->getRequestUri(), true);
        if ($rule === null) {
            return null;
        }
        usleep(($rule['delay'] ?? 0) * 1000);
        $headers = $rule['headers'] ?? [];
        if (isset($rule['jsonBody'])) {
            return new JsonResponse($rule['jsonBody'], $rule['status'] ?? 200, $headers, json: true);
        }
        if (isset($rule['status']) || isset($rule['body'])) {
            return response($rule['body'] ?? '', $rule['status'] ?? 200, $headers);
        }

        return null;
    }

    private static function claim(Attempt $attempt, string $method, string $target, bool $fault = false): ?array
    {
        $path = explode('?', $target, 2)[0];
        if (! str_starts_with($path, '/') || $path === '/_rehearsal' || str_starts_with($path, '/_rehearsal/')) {
            return null;
        }

        return AttemptData::update($attempt, $fault ? 'route-faults' : 'route-mocks', function (array &$rules) use ($method, $path, $target) {
            for ($i = count($rules) - 1; $i >= 0; $i--) {
                $rule = $rules[$i];
                $pattern = $rule['path'];
                $candidate = str_contains($pattern, '?') ? $target : $path;
                $regex = strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => '[^/]*']);
                if (($rule['method'] ?? $method) === $method && preg_match('~\A'.$regex.'\z~D', $candidate)) {
                    if ($rule['response']['once'] ?? false) {
                        array_splice($rules, $i, 1);
                    }

                    return $rule['response'];
                }
            }

            return null;
        });
    }
}

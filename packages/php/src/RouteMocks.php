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
        abort_if(! $fault && config('rehearsal.route_mocks') !== true, 404);
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 128_000, 422);
        $data = Validator::validate($input, [
            'path' => ['required', 'string', 'max:1024', 'starts_with:/', 'not_regex:/^\/\//'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'response' => ($fault ? 'required' : 'present').'|array:status,jsonBody,body,headers,delay,abort,networkError,once,times,gate,passthrough',
            'response.status' => 'sometimes|integer|min:200|max:599',
            'response.jsonBody' => 'sometimes|string|json|max:100000',
            'response.body' => 'sometimes|string|max:100000',
            'response.headers' => 'sometimes|array|max:20',
            'response.headers.*' => 'required|string|max:1024',
            'response.delay' => 'sometimes|integer|min:0|max:10000',
            'response.abort' => 'sometimes|boolean',
            'response.networkError' => 'sometimes|boolean',
            'response.once' => 'sometimes|boolean',
            'response.times' => 'sometimes|integer|min:1',
            'response.gate' => 'sometimes|string|regex:/\A[a-f0-9]{32}\z/',
            'response.passthrough' => 'sometimes|boolean',
        ]);
        $response = $data['response'];
        abort_if(isset($response['body'], $response['jsonBody'])
            || (! empty($response['once']) && isset($response['times']))
            || (! empty($response['passthrough']) && array_intersect_key($response, array_flip(['status', 'body', 'jsonBody', 'headers', 'abort', 'networkError']))), 422);
        foreach ($response['headers'] ?? [] as $name => $value) {
            abort_unless(preg_match('/\A[a-zA-Z0-9!#$%&\'*+.^_`|~-]+\z/', $name)
                && ! preg_match('/[\r\n]/', $value), 422);
        }
        if ($fault) {
            abort_unless(in_array($data['path'], config('rehearsal.fault_paths', []), true), 422);
            abort_if(! empty($response['abort']) || ! empty($response['networkError'])
                || isset($response['gate']) || ! empty($response['passthrough']), 422);
        }

        AttemptData::update($attempt, $fault ? 'route-faults' : 'route-mocks', function (array &$rules) use ($attempt, $data, $response): void {
            abort_if(count($rules) >= 64, 422);
            if (isset($response['gate'])) {
                AttemptData::update($attempt, 'route-gates', function (array &$gates) use ($response): void {
                    abort_if(count($gates) >= 64 || isset($gates[$response['gate']]), 422);
                    $gates[$response['gate']] = ['claimed' => false, 'released' => false];
                });
            }
            $rules[] = $data;
        });
    }

    public static function clear(Attempt $attempt, bool $fault): void
    {
        abort_if(! $fault && config('rehearsal.route_mocks') !== true, 404);
        AttemptData::update($attempt, $fault ? 'route-faults' : 'route-mocks', static function (array &$rules): void {
            $rules = [];
        });
    }

    public static function release(Attempt $attempt, array $input): void
    {
        abort_unless(config('rehearsal.route_mocks') === true, 404);
        $data = Validator::validate($input, ['gate' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/']]);
        AttemptData::update($attempt, 'route-gates', static function (array &$gates) use ($data): void {
            abort_unless(isset($gates[$data['gate']]), 404);
            $gates[$data['gate']]['released'] = true;
        });
    }

    public function gate(Request $request, Attempt $attempt, string $gate): JsonResponse
    {
        abort_unless($request->hasHeader(Protocol::MARKER), 403);
        $state = AttemptData::update($attempt, 'route-gates', static fn (array $gates) => $gates[$gate] ?? null);
        abort_if($state === null, 404);

        return response()->json(['data' => $state])->header('Cache-Control', 'no-store');
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
        if (! is_file($attempt->path('route-faults.json'))) {
            return null;
        }
        $rule = self::claim($attempt, $request->method(), $request->getRequestUri(), true);
        if ($rule === null) {
            return null;
        }
        usleep(($rule['delay'] ?? 0) * 1000);
        $headers = $rule['headers'] ?? [];
        if (isset($rule['jsonBody'])) {
            return new JsonResponse($rule['jsonBody'], $rule['status'] ?? 200, $headers, json: true);
        }
        if (isset($rule['status']) || isset($rule['body']) || $headers !== []) {
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

        return AttemptData::update($attempt, $fault ? 'route-faults' : 'route-mocks', function (array &$rules) use ($attempt, $method, $path, $target) {
            for ($i = count($rules) - 1; $i >= 0; $i--) {
                $rule = $rules[$i];
                $pattern = $rule['path'];
                $candidate = str_contains($pattern, '?') ? $target : $path;
                $regex = strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => '[^/]*']);
                if (($rule['method'] ?? $method) === $method && preg_match('~\A'.$regex.'\z~D', $candidate)) {
                    $response = $rule['response'];
                    if (($response['once'] ?? false) || ($response['times'] ?? null) == 1) {
                        array_splice($rules, $i, 1);
                    } elseif (isset($response['times'])) {
                        $rules[$i]['response']['times']--;
                    }
                    if (isset($response['gate'])) {
                        AttemptData::update($attempt, 'route-gates', static function (array &$gates) use ($response): void {
                            $gates[$response['gate']]['claimed'] = true;
                        });
                    }

                    return $response;
                }
            }

            return null;
        });
    }
}
